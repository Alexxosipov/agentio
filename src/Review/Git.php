<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Review;

use Symfony\Component\Process\Process;

/**
 * Runs git in one checkout (the main one or an epic worktree). Never asks for credentials and never quotes
 * non-ASCII paths, so the output can be parsed and shown as is.
 */
final readonly class Git
{
    public const int TIMEOUT = 120;

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
        $process = new Process(
            ['git', '-c', 'core.quotePath=false', ...$arguments],
            $this->directory,
            ['GIT_TERMINAL_PROMPT' => '0', 'GIT_MERGE_AUTOEDIT' => 'no'],
            null,
            self::TIMEOUT,
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
     * Changes of tracked files (`git status --porcelain` lines); untracked files are not counted unless asked.
     *
     * @return list<string>
     */
    public function changes(bool $untracked = false): array
    {
        return $this->lines('status', '--porcelain', '--untracked-files='.($untracked ? 'normal' : 'no'));
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
