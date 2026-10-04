<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

use Obrazmisli\Agentio\Settings;

/**
 * The Claude Code settings of the agents' headless sessions (passed with --settings): the session settings of
 * the package (permission rules, the agentio:guard hook), the permission rules of the project's own
 * .claude/settings.json, and the rules that depend on the project: agents may push the branches of its issues
 * (TP-*) and may never push to or switch to the development and the production branch.
 *
 * Claude Code ignores the allow rules of a directory whose workspace trust was never accepted (every new epic
 * worktree), which is why the project's rules are passed along.
 */
final readonly class SessionSettings
{
    /** The session settings of the package, relative to the package root. */
    public const string FILE = 'resources/claude/settings.json';

    public function __construct(private Settings $settings) {}

    public static function packageFile(): string
    {
        return dirname(__DIR__, 2).'/'.self::FILE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $settings = self::read(self::packageFile());
        $project = self::read($this->settings->basePath().'/.claude/settings.json');
        $permissions = is_array($settings['permissions'] ?? null) ? $settings['permissions'] : [];
        $projectPermissions = is_array($project['permissions'] ?? null) ? $project['permissions'] : [];
        $key = $this->settings->project();

        $own = [
            'allow' => ["Bash(git push -u origin {$key}-*)", "Bash(git push origin {$key}-*)"],
            'deny' => array_merge(...array_map(fn (string $branch): array => [
                "Bash(git push origin {$branch}*)",
                "Bash(git checkout {$branch}*)",
                "Bash(git switch {$branch}*)",
            ], $this->settings->protectedBranches())),
        ];

        foreach (['allow', 'deny'] as $list) {
            $permissions[$list] = array_values(array_unique([
                ...self::strings($permissions[$list] ?? []),
                ...self::strings($projectPermissions[$list] ?? []),
                ...$own[$list],
            ]));
        }

        $settings['permissions'] = $permissions;

        return $settings;
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], is_string(...)));
    }
}
