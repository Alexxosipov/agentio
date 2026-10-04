<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Installs the skills of the cycle (stubs/skills) into the host project's .claude/skills with the placeholders
 * rendered — the only files agentio adds to the project; the scripts, the session settings and the manual stay
 * in the package. writeEnvironment() records the connection and the machine settings in .env.
 *
 * Idempotent: a file with the expected content is left alone; a file that differs is updated only when it
 * still has the content of the previous install (see Manifest::$files) or with $force, otherwise skipped.
 * A skill file installed before whose stub is gone from the package is removed when nobody edited it.
 */
final class Installer
{
    /** Stub directory => host directory. */
    public const array ROOTS = ['skills' => '.claude/skills'];

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
     * Install the skills and remove the skill files of a previous install that the package no longer ships.
     *
     * @return list<FileChange>
     */
    public function install(): array
    {
        $changes = [];
        $stubFiles = $this->stubFiles();

        foreach ($stubFiles as $relative => $source) {
            $changes[] = $this->installFile($relative, $source);
        }

        foreach (array_diff_key($this->previousHashes, $stubFiles) as $relative => $hash) {
            $change = $this->removeObsoleteFile($relative, $hash);

            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /**
     * Set the given keys in .env (replaced on their line or appended); keys without a value are skipped and
     * values never appear in the notes.
     *
     * @param  array<string, string|null>  $values  E.g. YOUTRACK_URL, YOUTRACK_TOKEN, AGENTIO_WORKTREES_PATH
     */
    public function writeEnvironment(array $values): ?FileChange
    {
        $values = array_filter($values, fn (?string $value): bool => $value !== null && $value !== '');

        if ($values === []) {
            return null;
        }

        $env = new EnvFile($this->path('.env'));
        $exists = $env->exists();
        $updated = $env->contentWith($values);

        if ($exists && $updated === $env->content()) {
            return new FileChange('.env', FileStatus::Unchanged);
        }

        $this->write($this->path('.env'), $updated);

        return new FileChange('.env', $exists ? FileStatus::Updated : FileStatus::Created, 'set '.implode(', ', array_keys($values)));
    }

    /**
     * Hashes of the installed files, for Manifest::$files.
     *
     * @return array<string, string>
     */
    public function hashes(): array
    {
        return $this->hashes;
    }

    /**
     * Whether the project uses Laravel Boost (its MCP server is given to the agents only then).
     */
    public static function hasBoost(string $basePath): bool
    {
        $basePath = rtrim($basePath, '/');

        if (is_dir($basePath.'/vendor/laravel/boost')) {
            return true;
        }

        $composer = json_decode((string) @file_get_contents($basePath.'/composer.json'), true);

        return is_array($composer) && (isset($composer['require']['laravel/boost']) || isset($composer['require-dev']['laravel/boost']));
    }

    /**
     * Stub files to install: host path => stub path.
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
                if ($file->isFile()) {
                    $files[$hostRoot.substr($file->getPathname(), strlen($directory))] = $file->getPathname();
                }
            }
        }

        ksort($files);

        return $files;
    }

    private function installFile(string $relative, string $source): FileChange
    {
        $content = $this->placeholders->render((string) file_get_contents($source));
        $target = $this->path($relative);
        $hash = hash('sha256', $content);

        if (! is_file($target)) {
            $this->write($target, $content);
            $this->hashes[$relative] = $hash;

            return new FileChange($relative, FileStatus::Created);
        }

        $current = (string) file_get_contents($target);

        if ($current === $content) {
            $this->hashes[$relative] = $hash;

            return new FileChange($relative, FileStatus::Unchanged);
        }

        $pristine = ($this->previousHashes[$relative] ?? null) === hash('sha256', $current);

        if (! $this->force && ! $pristine) {
            if (isset($this->previousHashes[$relative])) {
                $this->hashes[$relative] = $this->previousHashes[$relative];
            }

            return new FileChange($relative, FileStatus::Skipped, 'differs from the stub (edited locally?); --force overwrites it');
        }

        $this->write($target, $content);
        $this->hashes[$relative] = $hash;

        return new FileChange($relative, FileStatus::Updated, $pristine ? 'new version of the stub' : 'overwritten (--force)');
    }

    /**
     * A file of a previous install whose stub is no longer in the package: removed (with the directories it
     * leaves empty) when it still has the installed content, kept with a warning when it was edited. Paths
     * outside .claude/skills are ignored; a file that is already gone is forgotten.
     */
    private function removeObsoleteFile(string $relative, string $hash): ?FileChange
    {
        $inRoot = array_filter(self::ROOTS, fn (string $root): bool => str_starts_with($relative, $root.'/'));

        if ($inRoot === [] || preg_match('#(^|/)\.\.?(/|$)#', $relative) === 1) {
            return null;
        }

        $target = $this->path($relative);

        if (! is_file($target)) {
            return null;
        }

        if (hash_file('sha256', $target) !== $hash) {
            $this->hashes[$relative] = $hash;

            return new FileChange($relative, FileStatus::Skipped, 'no longer part of agentio, but edited locally: kept, delete it by hand when you no longer need it');
        }

        if (! $this->dryRun) {
            unlink($target);
            $this->removeEmptyDirectories(dirname($relative));
        }

        return new FileChange($relative, FileStatus::Removed, 'no longer part of agentio');
    }

    /**
     * Remove the directory and its parents while they are empty, up to (not including) .claude/skills.
     */
    private function removeEmptyDirectories(string $relative): void
    {
        while (! in_array($relative, self::ROOTS, true) && str_contains($relative, '/') && is_dir($this->path($relative)) && scandir($this->path($relative)) === ['.', '..']) {
            rmdir($this->path($relative));
            $relative = dirname($relative);
        }
    }

    private function write(string $target, string $content): void
    {
        if ($this->dryRun) {
            return;
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        file_put_contents($target, $content);
    }

    private function path(string $relative): string
    {
        return rtrim($this->basePath, '/').'/'.$relative;
    }
}
