<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

use Obrazmisli\Agentio\Security\GuardHook;
use Obrazmisli\Agentio\Settings;

/**
 * The Claude Code settings of the agents' headless sessions (passed with --settings):
 *
 * - epic sessions (epic()): the permission rules of the package (resources/claude/settings.json) plus those of
 *   the project's own .claude/settings.json, and the rules that depend on the project: agents may push the
 *   branches of its issues (TP-*) and never push to or switch to the development and the production branch;
 * - planning sessions (planning()), which run in the developer's main checkout: read-only
 *   (resources/claude/planning.json) — the code is read, YouTrack is written through its MCP server.
 *
 * Both get the agentio guard as the PreToolUse hook of the Bash tool (GuardHook), with the protected branches
 * and the additional directories of the main checkout on its command line. Claude Code ignores the allow rules
 * of a directory whose workspace trust was never accepted (every new epic worktree), which is why the project's
 * rules are passed along.
 */
final readonly class SessionSettings
{
    /** The session settings of the package, relative to the package root. */
    public const string FILE = 'resources/claude/settings.json';

    /** The permissions of the planning sessions, relative to the package root. */
    public const string PLANNING_FILE = 'resources/claude/planning.json';

    public function __construct(private Settings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function epic(): array
    {
        $package = self::read(self::packageFile(self::FILE));
        $project = self::read($this->settings->basePath().'/.claude/settings.json');
        $key = $this->settings->project();

        return $this->compose($package, [
            'allow' => [
                ...self::rules($package, 'allow'),
                ...self::rules($project, 'allow'),
                "Bash(git push -u origin {$key}-*)",
                "Bash(git push origin {$key}-*)",
            ],
            'deny' => [...self::rules($package, 'deny'), ...self::rules($project, 'deny'), ...$this->branchRules()],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function planning(): array
    {
        $package = self::read(self::packageFile(self::FILE));
        $planning = self::read(self::packageFile(self::PLANNING_FILE));
        $project = self::read($this->settings->basePath().'/.claude/settings.json');

        return $this->compose($package, [
            'allow' => self::rules($planning, 'allow'),
            'deny' => [...self::rules($package, 'deny'), ...self::rules($planning, 'deny'), ...self::rules($project, 'deny'), ...$this->branchRules()],
        ]);
    }

    public function toJson(bool $planning = false): string
    {
        return (string) json_encode($planning ? $this->planning() : $this->epic(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The directories outside the project agents may change files in: the additionalDirectories of the main
     * checkout's Claude Code settings (never those of a worktree, which agents could edit), without the root.
     *
     * @return list<string>
     */
    public function additionalDirectories(): array
    {
        $directories = [];

        foreach (['settings.json', 'settings.local.json'] as $file) {
            $permissions = self::read($this->settings->basePath().'/.claude/'.$file)['permissions'] ?? [];

            foreach (is_array($permissions) && is_array($permissions['additionalDirectories'] ?? null) ? $permissions['additionalDirectories'] : [] as $directory) {
                $directory = is_string($directory) ? rtrim($directory, '/') : '';

                if (str_starts_with($directory, '/')) {
                    $directories[] = $directory;
                }
            }
        }

        return array_values(array_unique($directories));
    }

    /**
     * @param  array<mixed>  $package
     * @param  array{allow: list<string>, deny: list<string>}  $rules
     * @return array<string, mixed>
     */
    private function compose(array $package, array $rules): array
    {
        $permissions = is_array($package['permissions'] ?? null) ? $package['permissions'] : [];
        $permissions['allow'] = array_values(array_unique($rules['allow']));
        $permissions['deny'] = array_values(array_unique($rules['deny']));
        $package['permissions'] = $permissions;
        $package['hooks'] = ['PreToolUse' => [[
            'matcher' => 'Bash',
            'hooks' => [['type' => 'command', 'command' => GuardHook::command($this->settings->protectedBranches(), $this->additionalDirectories()), 'timeout' => 20]],
        ]]];

        return $package;
    }

    /**
     * @return list<string>
     */
    private function branchRules(): array
    {
        return array_merge(...array_map(fn (string $branch): array => [
            "Bash(git push origin {$branch}*)",
            "Bash(git checkout {$branch}*)",
            "Bash(git switch {$branch}*)",
        ], $this->settings->protectedBranches()));
    }

    private static function packageFile(string $file): string
    {
        return dirname(__DIR__, 2).'/'.$file;
    }

    /**
     * @return array<mixed>
     */
    private static function read(string $file): array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<mixed>  $settings
     * @return list<string>
     */
    private static function rules(array $settings, string $list): array
    {
        $permissions = is_array($settings['permissions'] ?? null) ? $settings['permissions'] : [];

        return array_values(array_filter(is_array($permissions[$list] ?? null) ? $permissions[$list] : [], is_string(...)));
    }
}
