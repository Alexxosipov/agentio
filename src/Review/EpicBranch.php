<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Review;

use Obrazmisli\Agentio\Git\Branches;
use Obrazmisli\Agentio\Git\Git;
use Obrazmisli\Agentio\Settings;

/**
 * The branch of an epic (named after its id, see Branches) as git sees it from the main checkout: its commits and
 * changed files relative to the development branch, whether it is merged or would conflict, and the epic's worktree.
 */
final readonly class EpicBranch
{
    /** Commits listed at most. */
    public const int COMMITS = 200;

    /** Bytes of a file diff returned at most. */
    public const int DIFF_BYTES = 300_000;

    public function __construct(
        public string $epicId,
        public string $name,
        public string $base,
        public Git $git,
        public ?string $worktree,
    ) {}

    /**
     * The branch of the epic, or null when the main checkout has none (the epic was never started, or the
     * branch was deleted after the merge).
     */
    public static function find(Settings $settings, string $epicId): ?self
    {
        $git = new Git($settings->basePath());
        $name = Branches::find($git, $epicId);

        if ($name === null) {
            return null;
        }

        $worktrees = $settings->worktreesPath();

        return new self($epicId, $name, $settings->baseBranch(), $git, $worktrees === null ? null : $worktrees.'/'.$epicId);
    }

    /**
     * The short hash of the branch tip.
     */
    public function head(): ?string
    {
        return $this->git->output('rev-parse', '--short', 'refs/heads/'.$this->name);
    }

    public function baseExists(): bool
    {
        return $this->git->run('rev-parse', '--verify', '--quiet', 'refs/heads/'.$this->base)->isSuccessful();
    }

    /**
     * The full hash of the branch tip.
     */
    public function sha(): ?string
    {
        return $this->git->output('rev-parse', '--verify', '--quiet', 'refs/heads/'.$this->name);
    }

    /**
     * Whether the base branch already contains the branch tip: the local one, or the one of origin as last fetched
     * (a pull request merged on GitHub).
     */
    public function isMerged(): bool
    {
        return $this->git->isAncestor('refs/heads/'.$this->name, 'refs/heads/'.$this->base)
            || $this->git->isAncestor('refs/heads/'.$this->name, 'refs/remotes/origin/'.$this->base);
    }

    /**
     * Commits of the branch missing from the base (ahead) and of the base missing from the branch (behind).
     *
     * @return array{ahead: int, behind: int}
     */
    public function divergence(): array
    {
        $counts = preg_split('/\s+/', (string) $this->git->output('rev-list', '--left-right', '--count', $this->range('...')));

        return ['ahead' => (int) ($counts[1] ?? 0), 'behind' => (int) ($counts[0] ?? 0)];
    }

    /**
     * The commits of the branch that the base does not have, newest first.
     *
     * @return list<array{hash: string, short: string, subject: string, author: string, date: string}>
     */
    public function commits(int $limit = self::COMMITS): array
    {
        $commits = [];

        foreach ($this->git->lines('log', '--max-count='.$limit, '--format=%H%x1f%h%x1f%s%x1f%an%x1f%aI', $this->range('..')) as $line) {
            $parts = explode("\x1f", $line);

            if (count($parts) === 5) {
                $commits[] = ['hash' => $parts[0], 'short' => $parts[1], 'subject' => $parts[2], 'author' => $parts[3], 'date' => $parts[4]];
            }
        }

        return $commits;
    }

    /**
     * The files the branch changes since it left the base, with their status (A, M, D, T) and line counts
     * (null for binary files).
     *
     * @return list<array{path: string, status: string, added: int|null, deleted: int|null}>
     */
    public function files(): array
    {
        $files = [];

        foreach ($this->git->lines('diff', '--name-status', '--no-renames', $this->range('...')) as $line) {
            [$status, $path] = array_pad(explode("\t", $line, 2), 2, '');
            $files[$path] = ['path' => $path, 'status' => substr($status, 0, 1), 'added' => null, 'deleted' => null];
        }

        foreach ($this->git->lines('diff', '--numstat', '--no-renames', $this->range('...')) as $line) {
            [$added, $deleted, $path] = array_pad(explode("\t", $line, 3), 3, '');

            if (isset($files[$path])) {
                $files[$path]['added'] = is_numeric($added) ? (int) $added : null;
                $files[$path]['deleted'] = is_numeric($deleted) ? (int) $deleted : null;
            }
        }

        return array_values($files);
    }

    /**
     * The unified diff of one changed file since the branch left the base, or null when the branch does not
     * change that file.
     *
     * @return array{path: string, diff: string, truncated: bool}|null
     */
    public function diff(string $path, int $limit = self::DIFF_BYTES): ?array
    {
        if (! in_array($path, array_column($this->files(), 'path'), true)) {
            return null;
        }

        $diff = $this->git->run('diff', '--no-renames', '--no-color', $this->range('...'), '--', $path)->getOutput();

        return ['path' => $path, 'diff' => mb_strcut($diff, 0, $limit), 'truncated' => strlen($diff) > $limit];
    }

    /**
     * The files that would conflict on a merge into the base: empty when it merges cleanly, null when this git
     * cannot tell without merging (`git merge-tree --write-tree` needs git 2.38).
     *
     * @return list<string>|null
     */
    public function conflicts(): ?array
    {
        $process = $this->git->run('merge-tree', '--write-tree', '--name-only', '--no-messages', 'refs/heads/'.$this->base, 'refs/heads/'.$this->name);

        return match ($process->getExitCode()) {
            0 => [],
            1 => array_slice(array_filter(explode("\n", trim($process->getOutput())), fn (string $line): bool => $line !== ''), 1),
            default => null,
        };
    }

    /**
     * The epic's worktree, when it exists.
     */
    public function worktreeGit(): ?Git
    {
        return $this->worktree !== null && file_exists($this->worktree.'/.git') ? new Git($this->worktree) : null;
    }

    private function range(string $dots): string
    {
        return 'refs/heads/'.$this->base.$dots.'refs/heads/'.$this->name;
    }
}
