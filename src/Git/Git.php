<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Git;

use Symfony\Component\Process\Process;

/**
 * Runs git in one checkout (the main one or an epic worktree). Never asks for credentials and never quotes
 * non-ASCII paths, so the output can be parsed and shown as is.
 */
final readonly class Git
{
    public const int TIMEOUT = 120;

    /** Seconds a fetch or a push may take. */
    public const int NETWORK_TIMEOUT = 300;

    public function __construct(private string $directory) {}

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Run a git command; the finished process tells the exit code and the output.
     */
    public function run(string ...$arguments): Process
    {
        return $this->runWithTimeout(self::TIMEOUT, ...$arguments);
    }

    /**
     * Run a git command that may take long (a merge runs the project's hooks); null waits forever.
     */
    public function runWithTimeout(?float $timeout, string ...$arguments): Process
    {
        $process = new Process(
            ['git', '-c', 'core.quotePath=false', ...$arguments],
            $this->directory,
            // No optional locks: the dashboard polls git status in checkouts where agents and humans commit.
            ['GIT_TERMINAL_PROMPT' => '0', 'GIT_MERGE_AUTOEDIT' => 'no', 'GIT_OPTIONAL_LOCKS' => '0'],
            null,
            $timeout,
        );
        $process->run();

        return $process;
    }

    /**
     * The trimmed output of a git command, or null when it failed.
     */
    public function output(string ...$arguments): ?string
    {
        $process = $this->run(...$arguments);

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    /**
     * The non-empty lines of a git command's output (empty when it failed).
     *
     * @return list<string>
     */
    public function lines(string ...$arguments): array
    {
        $output = $this->output(...$arguments);

        return $output === null || $output === '' ? [] : array_values(array_filter(explode("\n", $output), fn (string $line): bool => $line !== ''));
    }

    /**
     * The branch checked out here, or null on a detached HEAD.
     */
    public function currentBranch(): ?string
    {
        $branch = $this->output('branch', '--show-current');

        return $branch === null || $branch === '' ? null : $branch;
    }

    /**
     * Whether a local branch of that name exists.
     */
    public function branchExists(string $name): bool
    {
        return $this->run('show-ref', '--verify', '--quiet', 'refs/heads/'.$name)->isSuccessful();
    }

    /**
     * Whether the repository has at least one commit (a branch can be created from HEAD).
     */
    public function hasCommits(): bool
    {
        return $this->run('rev-parse', '--verify', '--quiet', 'HEAD')->isSuccessful();
    }

    /**
     * Changes of tracked files (`git status --porcelain` lines); untracked files are not counted unless asked.
     *
     * @return list<string>
     */
    public function changes(bool $untracked = false): array
    {
        return $this->lines('status', '--porcelain', '--untracked-files='.($untracked ? 'normal' : 'no'));
    }

    /**
     * Whether the repository has the remote (origin by default).
     */
    public function hasRemote(string $remote = 'origin'): bool
    {
        return $this->output('remote', 'get-url', $remote) !== null;
    }

    /**
     * Whether $ancestor is an ancestor of (or equal to) $descendant; false when one of them does not exist.
     */
    public function isAncestor(string $ancestor, string $descendant): bool
    {
        return $this->run('merge-base', '--is-ancestor', $ancestor, $descendant)->isSuccessful();
    }

    /**
     * Fetch branches of origin; the error, or null when it succeeded.
     */
    public function fetch(string ...$branches): ?string
    {
        $process = $this->runWithTimeout(self::NETWORK_TIMEOUT, 'fetch', '--quiet', 'origin', ...array_map(
            fn (string $branch): string => '+refs/heads/'.$branch.':refs/remotes/origin/'.$branch,
            $branches,
        ));

        return $process->isSuccessful() ? null : self::error($process);
    }

    /**
     * Push a local branch to the branch of the same name on origin (never forced); the error, or null.
     */
    public function push(string $branch): ?string
    {
        $process = $this->runWithTimeout(self::NETWORK_TIMEOUT, 'push', '--quiet', '--set-upstream', 'origin', 'refs/heads/'.$branch.':refs/heads/'.$branch);

        return $process->isSuccessful() ? null : self::error($process);
    }

    /**
     * Move the local branch forward to its fetched origin counterpart when that needs no merge: in place when it
     * is checked out here with nothing uncommitted, by moving the ref when no checkout has it. Returns why it was
     * left behind, or null when it is up to date.
     */
    public function fastForward(string $branch): ?string
    {
        $local = 'refs/heads/'.$branch;
        $remote = 'refs/remotes/origin/'.$branch;

        if (! $this->branchExists($branch) || $this->output('rev-parse', '--verify', '--quiet', $remote) === null || $this->isAncestor($remote, $local)) {
            return null;
        }

        if (! $this->isAncestor($local, $remote)) {
            return "локальная {$branch} разошлась с origin/{$branch}: обновите её вручную (git pull).";
        }

        if ($this->currentBranch() === $branch) {
            if ($this->changes() !== []) {
                return "в главном каталоге незакоммиченные изменения: обновите {$branch} вручную (git pull --ff-only).";
            }

            $process = $this->runWithTimeout(null, 'merge', '--ff-only', '--quiet', $remote);

            return $process->isSuccessful() ? null : "{$branch} не обновлена: ".self::error($process);
        }

        if (in_array('branch '.$local, $this->lines('worktree', 'list', '--porcelain'), true)) {
            return "{$branch} открыта в другом worktree: обновите её там (git pull --ff-only).";
        }

        $process = $this->run('update-ref', $local, $remote, (string) $this->output('rev-parse', $local));

        return $process->isSuccessful() ? null : "{$branch} не обновлена: ".self::error($process);
    }

    /**
     * The error of a failed git command: its stderr, else its stdout.
     */
    public static function error(Process $process): string
    {
        $error = trim($process->getErrorOutput());

        return $error !== '' ? $error : trim($process->getOutput());
    }
}
