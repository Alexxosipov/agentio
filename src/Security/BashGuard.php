<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Security;

use Obrazmisli\Agentio\Git\Branches;
use Symfony\Component\Process\Process;

/**
 * The second line of defence behind the permission rules of the headless agent sessions: decides whether a
 * Bash command of an agent is refused. Refused are pushes to protected branches, force pushes, deleting or
 * moving branches other than the branches of issues (TP-12, see Branches), history rewrites, destructive commands outside the project, access to
 * secrets and to the YouTrack token, and the agentio commands that only humans and the loop run.
 */
final readonly class BashGuard
{
    /**
     * @param  string  $project  The project directory (an epic worktree for epic sessions)
     * @param  list<string>  $protected  Branches agents never push to or switch to, the development branch first
     * @param  list<string>  $roots  Directories agents may change files in (the project and additional directories)
     */
    public function __construct(
        private string $project,
        private array $protected,
        private array $roots,
    ) {}

    /**
     * The reason the command is refused, or null when it may run.
     */
    public function reason(string $command, string $cwd): ?string
    {
        if (preg_match('/\.env\.(production|prod|staging)\b|\.claude\.json|\.ssh\/|id_(rsa|ed25519)|\.config\/gh\//i', $command) === 1) {
            return 'access to secrets or production environment files is not allowed.';
        }

        if (preg_match('/settings\.local\.json|(^|[\s\'"=<>\/:])\.env(?![\w.-])|config:show\s+[\'"]?agentio(\.youtrack(\.token)?)?([\s\'";|&]|$)|agentio\.youtrack|youtrack\.token/i', $command) === 1) {
            return '.env holds the YouTrack token: use .env.example or the config files; the YouTrack MCP server and php artisan agentio:yt use the connection themselves.';
        }

        if (preg_match('/\$\{?(ANTHROPIC_API_KEY|ANTHROPIC_AUTH_TOKEN)\b|\bYOUTRACK_TOKEN\b|\bprintenv\b|(^|[;&|]\s*)(env|set|export -p)\s*($|[;&|])|\/proc\/[0-9a-z]+\/environ/', $command) === 1) {
            return 'reading credentials from the environment is not allowed; the YouTrack MCP server and php artisan agentio:yt use them themselves.';
        }

        if (preg_match('/^\s*sudo\b|\bmkfs\b|\bdd\s+if=|\bchmod\s+-R\s+777\b|:\(\)\s*\{/', $command) === 1) {
            return 'privileged or destructive system command.';
        }

        foreach (self::segments($command) as $segment) {
            $words = self::words($segment);

            while ($words !== [] && preg_match('/^[A-Z_][A-Z0-9_]*=/', $words[0]) === 1) {
                array_shift($words);
            }

            $reason = match (true) {
                $words === [] => null,
                $words[0] === 'git' => $this->git(array_slice($words, 1), $cwd),
                $words[0] === 'php' => $this->artisan(array_slice($words, 1)),
                in_array($words[0], ['rm', 'rmdir', 'shred', 'truncate', 'mv', 'chmod', 'chown', 'find'], true) => $this->destructive($words, $cwd),
                default => null,
            };

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $args
     */
    private function git(array $args, string $cwd): ?string
    {
        while ($args !== [] && in_array($args[0], ['-C', '-c', '--git-dir', '--work-tree'], true)) {
            if ($args[0] === '-C' && isset($args[1]) && ! $this->isInside(self::resolvePath($args[1], $cwd))) {
                return 'git -C outside of the project directory.';
            }

            $args = array_slice($args, 2);
        }

        $sub = $args[0] ?? '';
        $rest = array_slice($args, 1);

        return match (true) {
            $sub === 'push' => $this->push($rest, $cwd),
            $sub === 'branch' && array_intersect($rest, ['-d', '-D', '--delete', '-m', '-M', '--move', '-f', '--force']) !== [] => $this->branchChange($rest),
            $sub === 'reset' && in_array('--hard', $rest, true) => 'git reset --hard is not allowed (it can destroy work of parallel agents).',
            $sub === 'clean' || ($sub === 'stash' && ! in_array($rest[0] ?? '', ['list', 'show'], true)) => "git {$sub} is not allowed (it can destroy work of parallel agents).",
            in_array($sub, ['checkout', 'restore'], true) && array_intersect($rest, ['.', ':/', '*']) !== [] => "git {$sub} of the whole tree is not allowed; restore specific files.",
            in_array($sub, ['checkout', 'switch'], true) && array_intersect($rest, $this->protected) !== [] => "switching to '{$this->protected[0]}' inside an agent worktree is not allowed.",
            in_array($sub, ['rebase', 'filter-branch', 'filter-repo', 'replace', 'update-ref'], true) => "history rewriting (git {$sub}) is not allowed.",
            $sub === 'worktree' && in_array($rest[0] ?? '', ['remove', 'prune'], true) => 'worktrees are managed by the agent loop (php artisan agentio:run).',
            default => null,
        };
    }

    /**
     * @param  list<string>  $rest
     */
    private function push(array $rest, string $cwd): ?string
    {
        foreach ($rest as $arg) {
            if (preg_match('/^(-f|--force.*|--mirror|--all|--prune|--tags)$/', $arg) === 1 || str_starts_with($arg, '+')) {
                return "force/mirror push ({$arg}) is not allowed.";
            }
        }

        $refs = array_values(array_filter($rest, fn (string $arg): bool => ! str_starts_with($arg, '-')));
        $refspecs = array_slice($refs, 1);

        if ($refspecs === []) {
            $current = new Process(['git', 'branch', '--show-current'], is_dir($cwd) ? $cwd : null);
            $current->run();
            $refspecs = [trim($current->getOutput())];
        }

        foreach ($refspecs as $refspec) {
            $target = str_contains($refspec, ':') ? mb_substr($refspec, (int) mb_strpos($refspec, ':') + 1) : $refspec;
            $target = (string) preg_replace('#^refs/heads/#', '', $target);

            if ($target === '' || str_starts_with($refspec, ':')) {
                $target = ltrim($refspec, ':');
            }

            if (in_array($target, $this->protected, true) || $target === 'HEAD') {
                return "pushing to protected branch '{$target}' is not allowed; humans merge into {$this->protected[0]}.";
            }

            if (! Branches::isWorkBranch($target)) {
                return "only the branches of issues (e.g. TP-12) may be pushed or deleted (got '{$target}').";
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $rest
     */
    private function branchChange(array $rest): ?string
    {
        foreach (array_filter($rest, fn (string $arg): bool => ! str_starts_with($arg, '-')) as $name) {
            if (! Branches::isWorkBranch($name)) {
                return "deleting, renaming or force-moving branch '{$name}' is not allowed; only the branches of issues (e.g. TP-12).";
            }
        }

        return null;
    }

    /**
     * The agentio commands that only humans and the loop run.
     *
     * @param  list<string>  $args
     */
    private function artisan(array $args): ?string
    {
        if (! str_ends_with($args[0] ?? '', 'artisan')) {
            return null;
        }

        $command = $args[1] ?? '';

        return match (true) {
            in_array($command, ['agentio:install', 'agentio:setup-youtrack', 'agentio:worktree', 'agentio:accept', 'tinker'], true) => "php artisan {$command} is run by humans, not by agents.",
            $command === 'agentio:run' && ! in_array('--dry-run', $args, true) => 'php artisan agentio:run is run by humans; agents may only use --dry-run.',
            default => null,
        };
    }

    /**
     * @param  list<string>  $words
     */
    private function destructive(array $words, string $cwd): ?string
    {
        if ($words[0] === 'find' && array_intersect(array_slice($words, 1), ['-delete', '-exec', '-execdir']) === []) {
            return null;
        }

        foreach (array_slice($words, 1) as $arg) {
            if (str_starts_with($arg, '-') || in_array($arg, ['{}', '\\;', ';', '+'], true)) {
                continue;
            }

            $path = self::resolvePath($arg, $cwd);

            if ((in_array($path, $this->roots, true) && $words[0] !== 'find') || ! $this->isInside($path)) {
                return "{$words[0]} on '{$arg}' outside of the project directory ({$this->project}) is not allowed.";
            }

            if (preg_match('#/\.git(/|$)#', $path) === 1) {
                return "{$words[0]} inside .git is not allowed.";
            }
        }

        return null;
    }

    private function isInside(string $path): bool
    {
        foreach ($this->roots as $root) {
            if ($path === $root || str_starts_with($path, $root.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $command): array
    {
        $parts = preg_split('/(?:&&|\|\||;|\||\n|\$\(|`)/', $command) ?: [];

        return array_values(array_filter(array_map(trim(...), $parts), fn (string $part): bool => $part !== ''));
    }

    /**
     * @return list<string>
     */
    private static function words(string $segment): array
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|\S+/', $segment, $matches);

        return array_map(fn (string $word): string => trim($word, '"\''), $matches[0]);
    }

    private static function resolvePath(string $path, string $cwd): string
    {
        $path = (string) preg_replace('#^~(?=/|$)#', (string) getenv('HOME'), $path);
        $absolute = str_starts_with($path, '/') ? $path : $cwd.'/'.$path;
        $parts = [];

        foreach (explode('/', $absolute) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return '/'.implode('/', $parts);
    }
}
