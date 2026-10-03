<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

use Symfony\Component\Process\ExecutableFinder;

/**
 * What the autonomous cycle needs on this machine: a git repository, the Claude Code CLI, PHP extensions,
 * shell tools of the loop scripts, a JS package manager (optional) and the YouTrack connection.
 */
final readonly class Preconditions
{
    public function __construct(
        private string $basePath,
        private string $claudeBinary,
        private ?string $youTrackUrl,
        private ?string $youTrackToken,
    ) {}

    /**
     * @return list<Check>
     */
    public function checks(): array
    {
        return [
            new Check('git repository', file_exists($this->basePath.'/.git'), true, 'run `git init` and commit the project: epics live on their own branches and worktrees'),
            new Check('git', $this->has('git'), true, 'install git'),
            new Check('composer', $this->has('composer'), true, 'install Composer: epic worktrees run `composer install`'),
            new Check('Claude Code CLI ('.$this->claudeBinary.')', $this->has($this->claudeBinary), true, 'install Claude Code (https://docs.claude.com/claude-code) and log in, or set AGENTIO_CLAUDE_BIN'),
            new Check('PHP extension curl', extension_loaded('curl'), true, 'scripts/yt.php talks to YouTrack with curl'),
            new Check('PHP extension mbstring', extension_loaded('mbstring'), true, 'scripts/yt.php needs mbstring'),
            new Check('PHP extension posix', extension_loaded('posix'), false, 'used to check whether agent sessions are alive'),
            new Check('PHP extension intl', extension_loaded('intl'), false, 'better branch slugs for non-Latin summaries (iconv is used otherwise)'),
            new Check('setsid', $this->has('setsid'), true, 'agent sessions are started with setsid (util-linux; on macOS: brew install util-linux)'),
            new Check('flock', $this->has('flock'), false, 'scripts/agent-commit.sh falls back to a mkdir lock without it'),
            new Check('bun or npm', $this->has('bun') || $this->has('npm'), false, 'needed only when the project has a frontend build'),
            new Check('YOUTRACK_URL', (string) $this->youTrackUrl !== '', true, 'set YOUTRACK_URL in .env (e.g. https://example.youtrack.cloud)'),
            new Check('YOUTRACK_TOKEN', (string) $this->youTrackToken !== '', true, 'set YOUTRACK_TOKEN in .env (YouTrack → Profile → Account Security → Tokens)'),
        ];
    }

    /**
     * @return list<Check>
     */
    public function failed(bool $requiredOnly = false): array
    {
        return array_values(array_filter($this->checks(), fn (Check $check): bool => ! $check->ok && ($check->required || ! $requiredOnly)));
    }

    /**
     * Whether a command is available: an executable path, or a name found in PATH.
     */
    public static function executable(string $command): ?string
    {
        if (str_contains($command, '/')) {
            return is_file($command) && is_executable($command) ? $command : null;
        }

        return (new ExecutableFinder)->find($command);
    }

    private function has(string $command): bool
    {
        return self::executable($command) !== null;
    }
}
