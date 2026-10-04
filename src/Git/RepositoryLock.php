<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Git;

use Closure;

/**
 * An exclusive lock of one git repository, shared by the main checkout and every worktree of it: a flock on a
 * file in the common git directory. It serializes the writes of the dashboard (a web request) and of the agent
 * loop (the command line) whatever cache store either of them has.
 */
final readonly class RepositoryLock
{
    public function __construct(private Git $git, private string $name) {}

    /**
     * Run the action holding the lock; null when somebody else holds it (the action does not run).
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $action
     * @return array{TResult}|null The action's result wrapped in a list, so that a null result can be told apart
     */
    public function attempt(Closure $action): ?array
    {
        $handle = fopen($this->file(), 'c');

        if ($handle === false) {
            return [$action()];
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return null;
            }

            try {
                return [$action()];
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    public function file(): string
    {
        $directory = $this->git->output('rev-parse', '--path-format=absolute', '--git-common-dir');

        return $directory !== null && is_dir($directory)
            ? $directory.'/agentio-'.$this->name.'.lock'
            : sys_get_temp_dir().'/agentio-'.$this->name.'-'.hash('xxh3', $this->git->directory()).'.lock';
    }
}
