<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

use Obrazmisli\Agentio\Git\Git;
use Obrazmisli\Agentio\GitHub\GitHub;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * What the autonomous cycle needs on this machine: a git repository whose .env is ignored (it gets the
 * YouTrack token) with the GitHub remote origin, the GitHub CLI logged in (epics and releases go through pull
 * requests; without it agentio:install stops), the Claude Code CLI, PHP extensions, the shell tools of the loop,
 * a JS package manager (optional) and the laravel-best-practices skill of Laravel Boost the analyst and the
 * architect follow (optional).
 */
final readonly class Preconditions
{
    public function __construct(
        private string $basePath,
        private string $claudeBinary,
    ) {}

    /**
     * @return list<Check>
     */
    public function checks(): array
    {
        $repository = file_exists($this->basePath.'/.git');

        return [
            new Check('git repository', $repository, true, 'run `git init` and commit the project: epics live on their own branches and worktrees'),
            new Check('.env ignored by git', ! $repository || $this->ignored('.env'), true, 'add .env to .gitignore: agentio:install writes the YouTrack token into it'),
            new Check('git', $this->has('git'), true, 'install git'),
            $this->gitHub(),
            new Check('git remote origin (GitHub)', $repository && (new Git($this->basePath))->hasRemote(), true, 'add the GitHub repository: git remote add origin git@github.com:<owner>/<repo>.git, then push the production and the development branch'),
            new Check('composer', $this->has('composer'), true, 'install Composer: epic worktrees run `composer install`'),
            new Check('Claude Code CLI ('.$this->claudeBinary.')', $this->has($this->claudeBinary), true, 'install Claude Code (https://docs.claude.com/claude-code) and log in, or set AGENTIO_CLAUDE_BIN'),
            new Check('PHP extension mbstring', extension_loaded('mbstring'), true, 'agentio needs mbstring'),
            new Check('PHP extension posix', extension_loaded('posix'), false, 'used to check whether agent sessions are alive'),
            new Check('setsid', $this->has('setsid'), true, 'agent sessions are started with setsid (util-linux; on macOS: brew install util-linux)'),
            new Check('flock', $this->has('flock'), false, 'agentio:commit falls back to a mkdir lock without it'),
            new Check('docker compose', $this->has('docker'), false, 'runs the local services of compose.yaml (PostgreSQL, Redis): install Docker with the compose plugin'),
            new Check('bun or npm', $this->has('bun') || $this->has('npm'), false, 'needed only when the project has a frontend build'),
            new Check('laravel-best-practices skill', is_file($this->basePath.'/.claude/skills/laravel-best-practices/SKILL.md'), false, 'the analyst and the architect design by it: composer require laravel/boost --dev, then php artisan boost:install with its skills, and commit .claude/skills'),
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
     * The GitHub CLI: installed and logged in, or agentio:install does not start (epics are accepted and releases
     * made only through pull requests).
     */
    public function gitHub(): Check
    {
        $github = new GitHub($this->basePath);

        return match (true) {
            ! $github->installed() => new Check('GitHub CLI (gh)', false, true, 'install it (https://cli.github.com), then run gh auth login: epics are accepted and releases made only through pull requests', blocking: true),
            ! $github->authenticated() => new Check('GitHub CLI (gh)', false, true, 'not logged in: run gh auth login (gh auth status must succeed)', blocking: true),
            default => new Check('GitHub CLI (gh)', true, true),
        };
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

    private function ignored(string $path): bool
    {
        $process = new Process(['git', 'check-ignore', '-q', '--no-index', $path], $this->basePath);
        $process->run();

        return $process->getExitCode() === 0;
    }
}
