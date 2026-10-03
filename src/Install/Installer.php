<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Copies the stubs into the host project with the placeholders rendered, and merges the shared files:
 * .claude/settings.json (union of permissions, the guard hook, MCP servers), the youtrack server of .mcp.json,
 * the agentio block of CLAUDE.md, .gitignore and .env.example lines, and the published config.
 * writeEnvironment() records the YouTrack connection in .env and .claude/settings.local.json.
 *
 * Idempotent: a file with the expected content is left alone; a file that differs is updated only when it
 * still has the content of the previous install (see Manifest::$files) or with $force, otherwise skipped.
 */
final class Installer
{
    /** Stub directory => host directory. */
    public const array ROOTS = ['claude' => '.claude', 'scripts' => 'scripts', 'docs' => 'docs'];

    public const string BLOCK_START = '<!-- agentio:start -->';

    public const string BLOCK_END = '<!-- agentio:end -->';

    /** .env and .claude/settings.local.json get the YouTrack token. */
    public const array GITIGNORE = ['/.agent-stop', '/storage/logs/agents', '/.claude/settings.local.json', '/.env'];

    /** Keys of the "env" block of .claude/settings.local.json: interactive sessions substitute them into .mcp.json. */
    public const array LOCAL_ENV = ['YOUTRACK_URL', 'YOUTRACK_TOKEN'];

    public const string MCP_SERVER = 'youtrack';

    private const string SETTINGS = '.claude/settings.json';

    private const string LOCAL_SETTINGS = '.claude/settings.local.json';

    private const string MCP_JSON = '.mcp.json';

    private const string AGENTS_MCP = '.claude/agents-mcp.json';

    private const string BOOST_SERVER = 'laravel-boost';

    private const int JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** @var array<string, string> */
    private array $hashes = [];

    /**
     * @param  array<string, string>  $previousHashes  Manifest::$files of the previous install
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $stubsPath,
        private readonly Placeholders $placeholders,
        private readonly bool $force = false,
        private readonly bool $dryRun = false,
        private readonly array $previousHashes = [],
    ) {}

    /**
     * Install everything; the package config is published to $configTarget when it is not there yet.
     *
     * @return list<FileChange>
     */
    public function install(string $configStub, string $configTarget): array
    {
        $changes = [];

        foreach ($this->stubFiles() as $relative => $source) {
            $changes[] = $this->installFile($relative, $source);
        }

        $changes[] = $this->mergeSettings();
        $changes[] = $this->mergeMcpJson();
        $changes[] = $this->updateClaudeMarkdown();
        $changes[] = $this->appendLines('.gitignore', self::GITIGNORE);
        $changes[] = $this->appendEnvExample();
        $changes[] = $this->publishConfig($configStub, $configTarget);

        return $changes;
    }

    /**
     * Record the YouTrack connection: every key with a value goes into .env (replaced on its line or appended),
     * YOUTRACK_URL and YOUTRACK_TOKEN also into the "env" block of .claude/settings.local.json (git-ignored),
     * which an interactive `claude` applies to the ${VAR}s of .mcp.json. Values never appear in the notes.
     *
     * @param  array<string, string|null>  $values  E.g. YOUTRACK_URL, YOUTRACK_TOKEN, AGENTIO_PROJECT
     * @return list<FileChange>
     */
    public function writeEnvironment(array $values): array
    {
        $values = array_filter($values, fn (?string $value): bool => $value !== null && $value !== '');

        if ($values === []) {
            return [];
        }

        $changes = [$this->updateEnv($values)];
        $local = array_intersect_key($values, array_flip(self::LOCAL_ENV));

        if ($local !== []) {
            $changes[] = $this->updateLocalSettings($local);
        }

        return $changes;
    }

    /**
     * Hashes of the installed stub files, for Manifest::$files.
     *
     * @return array<string, string>
     */
    public function hashes(): array
    {
        return $this->hashes;
    }

    /**
     * Whether the host project uses Laravel Boost (its MCP server is configured for the agents only then).
     */
    public function hasBoost(): bool
    {
        if (is_dir($this->path('vendor/laravel/boost'))) {
            return true;
        }

        $composer = json_decode((string) @file_get_contents($this->path('composer.json')), true);

        return is_array($composer) && (isset($composer['require']['laravel/boost']) || isset($composer['require-dev']['laravel/boost']));
    }

    /**
     * Stub files to copy: host path => stub path (settings.json is merged instead).
     *
     * @return array<string, string>
     */
    public function stubFiles(): array
    {
        $files = [];

        foreach (self::ROOTS as $stubRoot => $hostRoot) {
            $directory = $this->stubsPath.'/'.$stubRoot;

            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $relative = $hostRoot.substr($file->getPathname(), strlen($directory));

                if ($file->isFile() && $relative !== self::SETTINGS) {
                    $files[$relative] = $file->getPathname();
                }
            }
        }

        ksort($files);

        return $files;
    }

    private function installFile(string $relative, string $source): FileChange
    {
        $content = $this->placeholders->render((string) file_get_contents($source));
        $executable = is_executable($source) || str_ends_with($relative, '.sh');

        if ($relative === self::AGENTS_MCP && ! $this->hasBoost()) {
            $content = $this->withoutBoostServer($content);
        }

        $target = $this->path($relative);
        $hash = hash('sha256', $content);

        if (! is_file($target)) {
            $this->write($target, $content, $executable);
            $this->hashes[$relative] = $hash;

            return new FileChange($relative, FileStatus::Created);
        }

        $current = (string) file_get_contents($target);

        if ($current === $content) {
            $this->hashes[$relative] = $hash;

            if ($executable && ! is_executable($target)) {
                if (! $this->dryRun) {
                    chmod($target, 0755);
                }

                return new FileChange($relative, FileStatus::Updated, 'made executable');
            }

            return new FileChange($relative, FileStatus::Unchanged);
        }

        $pristine = ($this->previousHashes[$relative] ?? null) === hash('sha256', $current);

        if (! $this->force && ! $pristine) {
            if (isset($this->previousHashes[$relative])) {
                $this->hashes[$relative] = $this->previousHashes[$relative];
            }

            return new FileChange($relative, FileStatus::Skipped, 'differs from the stub (edited locally?); --force overwrites it');
        }

        $this->write($target, $content, $executable);
        $this->hashes[$relative] = $hash;

        return new FileChange($relative, FileStatus::Updated, $pristine ? 'new version of the stub' : 'overwritten (--force)');
    }

    /**
     * Merge .claude/settings.json: allow/deny lists and enabled MCP servers are united, the guard hook is added
     * when missing; everything else of the host file is kept.
     */
    private function mergeSettings(): FileChange
    {
        $stub = json_decode($this->placeholders->render((string) file_get_contents($this->stubsPath.'/claude/settings.json')), true);
        $stub = is_array($stub) ? $stub : [];

        if (! $this->hasBoost()) {
            $stub['enabledMcpjsonServers'] = array_values(array_diff((array) ($stub['enabledMcpjsonServers'] ?? []), [self::BOOST_SERVER]));
        }

        $target = $this->path(self::SETTINGS);

        if (! is_file($target)) {
            $this->write($target, $this->encode($this->mergeSettingsInto([], $stub)), false);

            return new FileChange(self::SETTINGS, FileStatus::Created);
        }

        $existing = json_decode((string) file_get_contents($target), true);

        if (! is_array($existing)) {
            return new FileChange(self::SETTINGS, FileStatus::Skipped, 'not valid JSON, left as is');
        }

        $merged = $this->mergeSettingsInto($existing, $stub);

        if ($merged === $existing) {
            return new FileChange(self::SETTINGS, FileStatus::Unchanged);
        }

        $this->write($target, $this->encode($merged), false);

        return new FileChange(self::SETTINGS, FileStatus::Updated, 'merged: permissions, hooks, MCP servers');
    }

    /**
     * Add the youtrack server of .claude/agents-mcp.json to the project's .mcp.json (committed, so it holds
     * only ${YOUTRACK_URL} and ${YOUTRACK_TOKEN}); the other servers (e.g. laravel-boost) are kept. A youtrack
     * server of the project's own is replaced only with --force.
     */
    private function mergeMcpJson(): FileChange
    {
        $stub = json_decode((string) file_get_contents($this->stubsPath.'/claude/agents-mcp.json'), true);
        $server = is_array($stub) && is_array($stub['mcpServers'][self::MCP_SERVER] ?? null) ? $stub['mcpServers'][self::MCP_SERVER] : [];
        $target = $this->path(self::MCP_JSON);

        if (! is_file($target)) {
            $this->write($target, $this->encode(['mcpServers' => [self::MCP_SERVER => $server]]), false);

            return new FileChange(self::MCP_JSON, FileStatus::Created, 'MCP server '.self::MCP_SERVER);
        }

        $config = json_decode((string) file_get_contents($target), true);

        if (! is_array($config) || ! is_array($config['mcpServers'] ?? [])) {
            return new FileChange(self::MCP_JSON, FileStatus::Skipped, 'not valid JSON, left as is');
        }

        $servers = (array) ($config['mcpServers'] ?? []);
        $current = $servers[self::MCP_SERVER] ?? null;

        if ($current === $server) {
            return new FileChange(self::MCP_JSON, FileStatus::Unchanged);
        }

        if ($current !== null && ! $this->force) {
            return new FileChange(self::MCP_JSON, FileStatus::Skipped, 'has its own '.self::MCP_SERVER.' MCP server; --force replaces it');
        }

        $config['mcpServers'] = [...$servers, self::MCP_SERVER => $server];
        $this->write($target, $this->encode($config), false);

        return new FileChange(self::MCP_JSON, FileStatus::Updated, $current === null
            ? 'added MCP server '.self::MCP_SERVER
            : 'replaced MCP server '.self::MCP_SERVER.' (--force)');
    }

    /**
     * @param  array<string, string>  $values
     */
    private function updateEnv(array $values): FileChange
    {
        $env = new EnvFile($this->path('.env'));
        $exists = $env->exists();
        $updated = $env->contentWith($values);

        if ($exists && $updated === $env->content()) {
            return new FileChange('.env', FileStatus::Unchanged);
        }

        $this->write($this->path('.env'), $updated, false);

        return new FileChange('.env', $exists ? FileStatus::Updated : FileStatus::Created, 'set '.implode(', ', array_keys($values)));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function updateLocalSettings(array $values): FileChange
    {
        $target = $this->path(self::LOCAL_SETTINGS);
        $exists = is_file($target);
        $settings = $exists ? json_decode((string) file_get_contents($target), true) : [];

        if (! is_array($settings) || ! is_array($settings['env'] ?? [])) {
            return new FileChange(self::LOCAL_SETTINGS, FileStatus::Skipped, 'not valid JSON, left as is: put '.implode(', ', array_keys($values)).' into its "env" by hand');
        }

        $merged = $settings;
        $merged['env'] = [...(array) ($settings['env'] ?? []), ...$values];

        if ($exists && $merged === $settings) {
            return new FileChange(self::LOCAL_SETTINGS, FileStatus::Unchanged);
        }

        $this->write($target, $this->encode($merged), false);

        return new FileChange(self::LOCAL_SETTINGS, $exists ? FileStatus::Updated : FileStatus::Created, 'env: '.implode(', ', array_keys($values)));
    }

    /**
     * @param  array<array-key, mixed>  $settings
     * @param  array<array-key, mixed>  $stub
     * @return array<array-key, mixed>
     */
    private function mergeSettingsInto(array $settings, array $stub): array
    {
        if (isset($stub['$schema']) && ! isset($settings['$schema'])) {
            $settings = ['$schema' => $stub['$schema'], ...$settings];
        }

        foreach (['allow', 'deny'] as $list) {
            $theirs = (array) ($settings['permissions'][$list] ?? []);
            $ours = (array) ($stub['permissions'][$list] ?? []);

            if ($theirs !== [] || $ours !== []) {
                $settings['permissions'] = (array) ($settings['permissions'] ?? []);
                $settings['permissions'][$list] = array_values(array_unique([...$theirs, ...$ours], SORT_REGULAR));
            }
        }

        foreach ((array) ($stub['hooks'] ?? []) as $event => $groups) {
            foreach ((array) $groups as $group) {
                $existing = (array) ($settings['hooks'][$event] ?? []);

                if (! $this->hasHooksOf((array) $group, $existing)) {
                    $settings['hooks'] = (array) ($settings['hooks'] ?? []);
                    $settings['hooks'][$event] = [...$existing, $group];
                }
            }
        }

        $servers = array_values(array_unique([
            ...(array) ($settings['enabledMcpjsonServers'] ?? []),
            ...(array) ($stub['enabledMcpjsonServers'] ?? []),
        ], SORT_REGULAR));

        if ($servers !== []) {
            $settings['enabledMcpjsonServers'] = $servers;
        }

        return $settings;
    }

    /**
     * Whether every hook command of the group is already configured for the event (compared by the
     * .claude/hooks/<script> it runs, or by the whole command).
     *
     * @param  array<array-key, mixed>  $group
     * @param  array<array-key, mixed>  $existingGroups
     */
    private function hasHooksOf(array $group, array $existingGroups): bool
    {
        $configured = [];

        foreach ($existingGroups as $existingGroup) {
            foreach ((array) (is_array($existingGroup) ? ($existingGroup['hooks'] ?? []) : []) as $hook) {
                $configured[] = is_array($hook) && is_string($hook['command'] ?? null) ? $hook['command'] : '';
            }
        }

        foreach ((array) ($group['hooks'] ?? []) as $hook) {
            $command = is_array($hook) && is_string($hook['command'] ?? null) ? $hook['command'] : '';
            $needle = preg_match('#\.claude/hooks/[\w.-]+#', $command, $match) === 1 ? $match[0] : $command;
            $found = false;

            foreach ($configured as $candidate) {
                $found = $found || ($needle !== '' && str_contains($candidate, $needle));
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Insert or replace the agentio block of CLAUDE.md (a new block goes first).
     */
    private function updateClaudeMarkdown(): FileChange
    {
        $block = self::BLOCK_START."\n"
            .rtrim($this->placeholders->render((string) file_get_contents($this->stubsPath.'/claude-md-block.md')))."\n"
            .self::BLOCK_END;
        $target = $this->path('CLAUDE.md');

        if (! is_file($target)) {
            $this->write($target, $block."\n", false);

            return new FileChange('CLAUDE.md', FileStatus::Created);
        }

        $current = (string) file_get_contents($target);
        $start = strpos($current, self::BLOCK_START);
        $end = $start === false ? false : strpos($current, self::BLOCK_END, $start);

        $updated = $start !== false && $end !== false
            ? substr($current, 0, $start).$block.substr($current, $end + strlen(self::BLOCK_END))
            : $block."\n\n".ltrim($current);

        if ($updated === $current) {
            return new FileChange('CLAUDE.md', FileStatus::Unchanged);
        }

        $this->write($target, $updated, false);

        return new FileChange('CLAUDE.md', FileStatus::Updated, $start !== false && $end !== false ? 'agentio block replaced' : 'agentio block inserted at the top');
    }

    /**
     * @param  list<string>  $lines
     */
    private function appendLines(string $relative, array $lines): FileChange
    {
        $target = $this->path($relative);
        $current = is_file($target) ? (string) file_get_contents($target) : null;
        $present = array_map(fn (string $line): string => '/'.ltrim(trim($line), '/'), explode("\n", (string) $current));
        $missing = array_values(array_filter($lines, fn (string $line): bool => ! in_array('/'.ltrim($line, '/'), $present, true)));

        if ($missing === []) {
            return new FileChange($relative, FileStatus::Unchanged);
        }

        $this->write($target, $this->appended($current, $missing), false);

        return new FileChange($relative, $current === null ? FileStatus::Created : FileStatus::Updated, 'added '.implode(', ', $missing));
    }

    private function appendEnvExample(): FileChange
    {
        $relative = '.env.example';
        $target = $this->path($relative);
        $current = is_file($target) ? (string) file_get_contents($target) : null;
        $missing = [];

        foreach (['YOUTRACK_URL' => '', 'YOUTRACK_TOKEN' => '', 'AGENTIO_PROJECT' => $this->placeholders->project] as $key => $value) {
            if (preg_match('/^[ \t]*#?[ \t]*'.$key.'=/m', (string) $current) !== 1) {
                $missing[] = $key.'='.$value;
            }
        }

        if ($missing === []) {
            return new FileChange($relative, FileStatus::Unchanged);
        }

        $this->write($target, $this->appended($current, $missing), false);

        return new FileChange($relative, $current === null ? FileStatus::Created : FileStatus::Updated, 'added '.implode(', ', $missing));
    }

    private function publishConfig(string $configStub, string $configTarget): FileChange
    {
        $relative = str_starts_with($configTarget, rtrim($this->basePath, '/').'/')
            ? substr($configTarget, strlen(rtrim($this->basePath, '/')) + 1)
            : $configTarget;

        if (is_file($configTarget)) {
            return new FileChange($relative, FileStatus::Unchanged, 'already published');
        }

        $this->write($configTarget, (string) file_get_contents($configStub), false);

        return new FileChange($relative, FileStatus::Created, 'published the package config');
    }

    /**
     * @param  list<string>  $lines
     */
    private function appended(?string $current, array $lines): string
    {
        $prefix = $current === null || $current === '' ? '' : rtrim($current, "\n")."\n\n";

        return $prefix."# agentio\n".implode("\n", $lines)."\n";
    }

    private function withoutBoostServer(string $json): string
    {
        $config = json_decode($json, true);

        if (! is_array($config) || ! isset($config['mcpServers'][self::BOOST_SERVER])) {
            return $json;
        }

        unset($config['mcpServers'][self::BOOST_SERVER]);

        return $this->encode($config);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function encode(array $data): string
    {
        return json_encode($data, self::JSON_FLAGS)."\n";
    }

    private function write(string $target, string $content, bool $executable): void
    {
        if ($this->dryRun) {
            return;
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        file_put_contents($target, $content);

        if ($executable) {
            chmod($target, 0755);
        }
    }

    private function path(string $relative): string
    {
        return rtrim($this->basePath, '/').'/'.$relative;
    }
}
