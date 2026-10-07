<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Security;

use Obrazmisli\Agentio\Git\Branches;
use Symfony\Component\Process\Process;

/**
 * The second line of defence behind the permission rules of the headless agent sessions: decides whether a
 * Bash command of an agent is refused. Refused are pushes to protected branches, force pushes, deleting or
 * moving branches other than the branches of issues (TP-12, see Branches), merges into protected branches,
 * history rewrites, git subcommands agents have no use for (aliases included), every GitHub CLI command that is
 * not a read (merging and opening pull requests is the business of agentio and of the developer), destructive
 * commands and writes outside the project, writes to the agents' own settings and skills, access to secrets and
 * to the YouTrack token, and the agentio commands that only humans and the loop run.
 *
 * The command line is split into simple commands by a small shell lexer (quotes, escapes, command substitution,
 * lists and pipes), and a `cd` moves the directory the following commands are resolved against. It is not a
 * shell: the permission rules (and, for a hard boundary, a sandbox) stay the first line of defence.
 */
final readonly class BashGuard
{
    /** The git subcommands agents use; anything else, git aliases included, is refused. */
    private const array GIT_SUBCOMMANDS = [
        'add', 'apply', 'blame', 'branch', 'cat-file', 'check-ignore', 'checkout', 'cherry-pick', 'commit', 'config',
        'describe', 'diff', 'diff-tree', 'fetch', 'for-each-ref', 'grep', 'help', 'log', 'ls-files', 'ls-tree',
        'merge', 'merge-base', 'mv', 'name-rev', 'pull', 'push', 'reflog', 'remote', 'reset', 'restore', 'rev-list',
        'rev-parse', 'revert', 'rm', 'shortlog', 'show', 'show-ref', 'stash', 'status', 'switch', 'tag', 'version',
        'worktree',
    ];

    /** The GitHub CLI commands agents may run: reads only. */
    private const array GH_READS = [
        'pr' => ['view', 'list', 'diff', 'checks', 'status'],
        'run' => ['view', 'list'],
        'repo' => ['view'],
        'auth' => ['status'],
    ];

    /** Commands that change or remove every file they are given. */
    private const array WRITING = ['rm', 'rmdir', 'shred', 'truncate', 'mv', 'chmod', 'chown', 'touch', 'tee', 'mkdir'];

    /** Commands that write only to their last argument (or to the directory of -t). */
    private const array COPYING = ['cp', 'ln', 'install', 'rsync'];

    /** Files that configure the agents themselves (relative to the project): read, never changed by agents. */
    private const string OWN_FILES = '#^(\.agentio\.json|\.claude/settings(\.local)?\.json|\.claude/skills/agentio-[^/]*(/|$)|vendor(/|$))#';

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
        foreach (self::segments($command) as $segment) {
            $words = self::words($segment);

            while ($words !== [] && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $words[0]) === 1) {
                array_shift($words);
            }

            $reason = $this->secrets(self::isAgentioData($words) ? self::withoutQuotedText($segment) : $segment)
                ?? $this->redirections($segment, $cwd)
                ?? match (true) {
                    $words === [] => null,
                    $words[0] === 'git' => $this->git(array_slice($words, 1), $cwd),
                    $words[0] === 'php' => $this->php(array_slice($words, 1)),
                    $words[0] === 'gh' => self::gh(array_slice($words, 1)),
                    in_array($words[0], ['composer', 'composer.phar'], true) => self::composer(array_slice($words, 1)),
                    $words[0] === 'find' => $this->find($words, $cwd),
                    in_array($words[0], ['sed', 'perl'], true) => $this->inPlaceEdit($words, $cwd),
                    in_array($words[0], self::WRITING, true) => $this->writing($words[0], array_slice($words, 1), $cwd),
                    in_array($words[0], self::COPYING, true) => $this->copying($words, $cwd),
                    default => null,
                };

            if ($reason !== null) {
                return $reason;
            }

            if (($words[0] ?? '') === 'cd') {
                $cwd = self::resolvePath($words[1] ?? (string) getenv('HOME'), $cwd);
            }
        }

        return null;
    }

    private function secrets(string $text): ?string
    {
        if (preg_match('/\.env\.(production|prod|staging)\b|\.claude\.json|\.ssh(\/|\b)|id_(rsa|ed25519)|\.config\/gh\//i', $text) === 1) {
            return 'access to secrets or production environment files is not allowed.';
        }

        if (preg_match('/settings\.local\.json|(^|[\s\'"=<>\/:])\.en(v(?![\w.-])|[?*\[])|config:show\s+[\'"]?agentio(\.youtrack(\.token)?)?([\s\'";|&]|$)|agentio\.youtrack|youtrack\.token/i', $text) === 1) {
            return '.env holds the YouTrack token: use .env.example or the config files; the YouTrack MCP server and php artisan agentio:yt use the connection themselves.';
        }

        if (preg_match('/\$\{?(ANTHROPIC_API_KEY|ANTHROPIC_AUTH_TOKEN)\b|\bYOUTRACK_TOKEN\b|\bprintenv\b|^\s*(env|set|export\s+-p|declare\s+-x)\s*$|\/proc\/[^\s\/]+\/environ/', $text) === 1) {
            return 'reading credentials from the environment is not allowed; the YouTrack MCP server and php artisan agentio:yt use them themselves.';
        }

        if (preg_match('/^\s*sudo\b|\bmkfs\b|\bdd\s+if=|\bchmod\s+-R\s+777\b|:\(\)\s*\{/', $text) === 1) {
            return 'privileged or destructive system command.';
        }

        return null;
    }

    /**
     * Output redirections (>, >>, 2>) must stay inside the project and away from the agents' own files.
     */
    private function redirections(string $segment, string $cwd): ?string
    {
        preg_match_all('/(?<![<>&\d])(?:&|\d)?>>?\|?\s*([^\s;&|<>]+)/', self::withoutQuotedText($segment), $matches);

        foreach ($matches[1] as $target) {
            $target = trim($target, '"\'');

            if ($target === '' || $target === '/dev/null' || $target === '/dev/stderr' || $target === '/dev/stdout') {
                continue;
            }

            $reason = $this->writablePath('redirection', $target, $cwd);

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
        while ($args !== [] && str_starts_with($args[0], '-')) {
            $option = $args[0];

            if ($option === '-C' && isset($args[1]) && ! $this->isInside(self::resolvePath($args[1], $cwd))) {
                return 'git -C outside of the project directory.';
            }

            if ($option === '-c' && preg_match('/^(core\.quotepath|color\.[a-z.]+)=/i', $args[1] ?? '') !== 1) {
                return 'git -c '.($args[1] ?? '').' is not allowed (configuration can run commands).';
            }

            if (preg_match('/^--(git-dir|work-tree|exec-path|namespace)(=|$)/', $option) === 1) {
                return "git {$option} is not allowed.";
            }

            $args = array_slice($args, in_array($option, ['-C', '-c'], true) ? 2 : 1);
        }

        $sub = $args[0] ?? '';
        $rest = array_slice($args, 1);
        $flags = self::flags($rest);

        if ($sub !== '' && ! in_array($sub, self::GIT_SUBCOMMANDS, true)) {
            return "git {$sub} is not a git command agents use (git aliases are not allowed).";
        }

        return match (true) {
            $sub === 'push' => $this->push($rest, $cwd),
            $sub === 'branch' && array_intersect($flags, ['-d', '-D', '--delete', '-m', '-M', '--move', '-c', '-C', '--copy', '-f', '--force']) !== [] => $this->branchChange($rest),
            $sub === 'reset' && in_array('--hard', $rest, true) => 'git reset --hard is not allowed (it can destroy work of parallel agents).',
            $sub === 'stash' && ! in_array($rest[0] ?? '', ['list', 'show'], true) => 'git stash is not allowed (it can destroy work of parallel agents).',
            in_array($sub, ['checkout', 'restore'], true) && array_intersect($rest, ['.', ':/', '*']) !== [] => "git {$sub} of the whole tree is not allowed; restore specific files.",
            in_array($sub, ['checkout', 'switch'], true) && array_intersect($rest, $this->protected) !== [] => "switching to '{$this->protected[0]}' inside an agent worktree is not allowed.",
            in_array($sub, ['checkout', 'switch'], true) && array_intersect($flags, ['-b', '-B', '-c', '-C', '--orphan']) !== [] => 'creating branches is the business of the agent loop: work on the branch of the epic.',
            $sub === 'merge' && in_array($this->currentBranch($cwd), $this->protected, true) => 'merging into '.$this->currentBranch($cwd).' is not allowed; humans accept epics.',
            $sub === 'commit' && array_intersect($flags, ['--amend', '-a', '--all']) !== [] => 'git commit --amend / --all is not allowed: commit only your files with php artisan agentio:commit.',
            $sub === 'add' && (array_intersect($rest, ['.', ':/', '*']) !== [] || array_intersect($flags, ['-A', '--all', '-u', '--update']) !== []) => 'git add of the whole tree is not allowed: commit only your files with php artisan agentio:commit.',
            $sub === 'tag' && ! self::onlyListsTags($rest) => 'creating, moving or deleting tags is not allowed.',
            $sub === 'remote' && ! in_array($rest[0] ?? '-v', ['-v', '--verbose', 'show', 'get-url'], true) => 'changing remotes is not allowed.',
            $sub === 'config' && ! self::onlyReadsConfig($rest) => 'changing the git configuration is not allowed.',
            $sub === 'worktree' && ($rest[0] ?? 'list') !== 'list' => 'worktrees are managed by the agent loop (php artisan agentio:run).',
            in_array($sub, ['rm', 'mv'], true) => $this->writing('git '.$sub, $rest, $cwd),
            default => null,
        };
    }

    /**
     * @param  list<string>  $rest
     */
    private function push(array $rest, string $cwd): ?string
    {
        foreach ($rest as $arg) {
            if (preg_match('/^(--force.*|--mirror|--all|--prune|--tags|--follow-tags)$/', $arg) === 1 || str_starts_with($arg, '+')) {
                return "force/mirror push ({$arg}) is not allowed.";
            }
        }

        if (in_array('-f', self::flags($rest), true)) {
            return 'force/mirror push (-f) is not allowed.';
        }

        $refs = array_values(array_filter($rest, fn (string $arg): bool => ! str_starts_with($arg, '-')));
        $refspecs = array_slice($refs, 1);

        if ($refspecs === []) {
            $refspecs = [(string) $this->currentBranch($cwd)];
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
     * `php -i`, code reading the environment, and the agentio commands that only humans and the loop run.
     *
     * @param  list<string>  $args
     */
    private function php(array $args): ?string
    {
        if (array_intersect($args, ['-i', '--info']) !== []) {
            return 'php -i prints the environment, which holds credentials.';
        }

        if (in_array('-r', $args, true) && preg_match('/\bphpinfo\b|\bgetenv\b|\$_(ENV|SERVER)\b|\benviron\b/', implode(' ', $args)) === 1) {
            return 'reading credentials from the environment is not allowed; the YouTrack MCP server and php artisan agentio:yt use them themselves.';
        }

        $rest = [];

        foreach ($args as $index => $arg) {
            if (str_ends_with($arg, 'artisan')) {
                $rest = array_slice($args, $index + 1);

                break;
            }
        }

        // Options of artisan itself may come before the command name: php artisan -n tinker.
        while ($rest !== [] && str_starts_with($rest[0], '-')) {
            $rest = array_slice($rest, $rest[0] === '--env' ? 2 : 1);
        }

        $command = $rest[0] ?? '';

        return match (true) {
            in_array($command, ['agentio:install', 'agentio:setup-youtrack', 'agentio:worktree', 'agentio:accept', 'agentio:release', 'tinker'], true) => "php artisan {$command} is run by humans, not by agents.",
            $command === 'agentio:run' && ! self::onlyDryRun(array_slice($rest, 1)) => 'php artisan agentio:run is run by humans; agents may only use --dry-run (with --epic= or --no-plan).',
            default => null,
        };
    }

    /**
     * The GitHub CLI only reads: an epic is published with php artisan agentio:pr, and pull requests are merged by
     * the developer (the dashboard, agentio:accept, the Telegram bot), never by agents.
     *
     * @param  list<string>  $args
     */
    private static function gh(array $args): ?string
    {
        $words = array_values(array_filter($args, fn (string $arg): bool => ! str_starts_with($arg, '-')));
        [$group, $command] = [$words[0] ?? '', $words[1] ?? ''];

        return in_array($command, self::GH_READS[$group] ?? [], true)
            ? null
            : "gh {$group} {$command} is not allowed: agents only read GitHub; publish an epic with php artisan agentio:pr, the developer merges pull requests.";
    }

    /**
     * Agents change the dependencies of the project only to add Saloon (saloonphp/*), the HTTP client of the
     * integrations without an SDK; everything else is a decision of the human.
     *
     * @param  list<string>  $args
     */
    private static function composer(array $args): ?string
    {
        while ($args !== [] && str_starts_with($args[0], '-')) {
            $args = array_slice($args, 1);
        }

        $command = $args[0] ?? '';

        if (in_array($command, ['remove', 'rm', 'uninstall', 'update', 'u', 'upgrade', 'bump', 'global', 'create-project', 'config'], true)) {
            return "composer {$command} changes the dependencies or the setup of the project: that is a decision of the human (ask in [AGENT:BLOCKED]).";
        }

        if (! in_array($command, ['require', 'req', 'r'], true)) {
            return null;
        }

        $packages = array_values(array_filter(array_slice($args, 1), fn (string $arg): bool => ! str_starts_with($arg, '-')));

        foreach ($packages as $package) {
            if (preg_match('#^saloonphp/[a-z0-9-]+(:[^\s]+)?$#', $package) !== 1) {
                return "composer require {$package}: agents may only add saloonphp/* packages (Saloon, skill agentio-saloon); any other dependency is a decision of the human (ask in [AGENT:BLOCKED]).";
            }
        }

        if ($packages === [] || array_intersect(array_slice($args, 1), ['--dev', '-D']) !== []) {
            return 'composer require: name the saloonphp/* packages, as dependencies of the application (not --dev).';
        }

        return null;
    }

    /**
     * @param  list<string>  $words
     */
    private function find(array $words, string $cwd): ?string
    {
        if (array_intersect($words, ['-exec', '-execdir', '-ok', '-okdir', '-fprint', '-fprint0', '-fprintf', '-fls']) !== []) {
            return 'find -exec and the writing actions of find are not allowed: use grep -r, or list the files and act on each.';
        }

        if (! in_array('-delete', $words, true)) {
            return null;
        }

        $paths = array_values(array_filter(array_slice($words, 1), fn (string $word): bool => ! str_starts_with($word, '-') && ! str_contains($word, '*')));

        return $this->writing('find -delete', $paths === [] ? ['.'] : [$paths[0]], $cwd, allowRoots: true);
    }

    /**
     * sed -i and perl -i edit files in place: like any write, inside the project and not the agents' own files.
     *
     * @param  list<string>  $words
     */
    private function inPlaceEdit(array $words, string $cwd): ?string
    {
        $arguments = array_slice($words, 1);
        $inPlace = array_filter($arguments, fn (string $arg): bool => str_starts_with($arg, '--in-place') || preg_match('/^-[a-zA-Z]*i/', $arg) === 1);

        if ($inPlace === []) {
            return null;
        }

        // The script is the first argument unless it comes with -e / -f; the files follow.
        $files = [];
        $script = false;

        for ($i = 0; $i < count($arguments); $i++) {
            if (in_array($arguments[$i], ['-e', '--expression', '-f', '--file'], true) || preg_match('/^-[a-zA-Z]*[ef]$/', $arguments[$i]) === 1) {
                $script = true;
                $i++;
            } elseif (! str_starts_with($arguments[$i], '-')) {
                $files[] = $arguments[$i];
            }
        }

        foreach ($script ? $files : array_slice($files, 1) as $file) {
            $reason = $this->writablePath($words[0].' -i', $file, $cwd);

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * cp, ln, install and rsync write to their last argument (or to the directory given with -t).
     *
     * @param  list<string>  $words
     */
    private function copying(array $words, string $cwd): ?string
    {
        $arguments = array_slice($words, 1);

        foreach ($arguments as $index => $arg) {
            if (in_array($arg, ['-t', '--target-directory'], true) && isset($arguments[$index + 1])) {
                return $this->writablePath($words[0], $arguments[$index + 1], $cwd);
            }

            if (str_starts_with($arg, '--target-directory=')) {
                return $this->writablePath($words[0], substr($arg, 19), $cwd);
            }
        }

        $paths = array_values(array_filter($arguments, fn (string $arg): bool => ! str_starts_with($arg, '-')));

        return count($paths) < 2 ? null : $this->writablePath($words[0], $paths[count($paths) - 1], $cwd);
    }

    /**
     * Every path argument must be writable (see writablePath()); the project directory itself is not, unless
     * $allowRoots (find -delete searches it).
     *
     * @param  list<string>  $arguments
     */
    private function writing(string $command, array $arguments, string $cwd, bool $allowRoots = false): ?string
    {
        foreach ($arguments as $arg) {
            if (str_starts_with($arg, '-') || in_array($arg, ['{}', '\\;', ';', '+'], true)) {
                continue;
            }

            if (! $allowRoots && in_array(self::resolvePath($arg, $cwd), $this->roots, true)) {
                return "{$command} on '{$arg}' outside of the project directory ({$this->project}) is not allowed.";
            }

            $reason = $this->writablePath($command, $arg, $cwd);

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * A path agents may write: inside the project (or an additional directory), not in .git, not one of the
     * agents' own files.
     */
    private function writablePath(string $command, string $argument, string $cwd): ?string
    {
        $path = self::resolvePath($argument, $cwd);

        if (! $this->isInside($path)) {
            return "{$command} on '{$argument}' outside of the project directory ({$this->project}) is not allowed.";
        }

        if (preg_match('#/\.git(/|$)#', $path) === 1) {
            return "{$command} inside .git is not allowed.";
        }

        foreach ($this->roots as $root) {
            if (str_starts_with($path, $root.'/') && preg_match(self::OWN_FILES, substr($path, strlen($root) + 1)) === 1) {
                return "{$command} on '{$argument}' is not allowed: agents do not change the agentio settings, the agentio skills or vendor/.";
            }
        }

        return null;
    }

    private function isInside(string $path): bool
    {
        foreach ($this->roots as $root) {
            if ($root !== '' && $root !== '/' && ($path === $root || str_starts_with($path, $root.'/'))) {
                return true;
            }
        }

        return false;
    }

    private function currentBranch(string $cwd): ?string
    {
        // Without the directory git would answer for the repository the guard itself runs in.
        if (! is_dir($cwd)) {
            return null;
        }

        $process = new Process(['git', 'branch', '--show-current'], $cwd, ['GIT_OPTIONAL_LOCKS' => '0']);
        $process->run();
        $branch = trim($process->getOutput());

        return $process->isSuccessful() && $branch !== '' ? $branch : null;
    }

    /**
     * Whether the words are an agentio command whose quoted arguments are data (a plan, a comment, a commit
     * message) that is not checked for secrets: `php artisan agentio:yt …` and `php artisan agentio:commit …`.
     *
     * @param  list<string>  $words
     */
    private static function isAgentioData(array $words): bool
    {
        return ($words[0] ?? '') === 'php' && str_ends_with($words[1] ?? '', 'artisan') && in_array($words[2] ?? '', ['agentio:yt', 'agentio:commit'], true);
    }

    /**
     * @param  list<string>  $options
     */
    private static function onlyDryRun(array $options): bool
    {
        return in_array('--dry-run', $options, true)
            && array_filter($options, fn (string $option): bool => ! in_array($option, ['--dry-run', '--no-plan'], true) && ! str_starts_with($option, '--epic=')) === [];
    }

    /**
     * @param  list<string>  $rest
     */
    private static function onlyListsTags(array $rest): bool
    {
        $flags = self::flags($rest);

        if (array_intersect($flags, ['-d', '--delete', '-f', '--force', '-a', '--annotate', '-s', '--sign', '-m', '--message', '-F', '--file']) !== []) {
            return false;
        }

        return array_intersect($flags, ['-l', '--list']) !== [] || array_filter($rest, fn (string $arg): bool => ! str_starts_with($arg, '-')) === [];
    }

    /**
     * @param  list<string>  $rest
     */
    private static function onlyReadsConfig(array $rest): bool
    {
        $flags = self::flags($rest);
        $positional = array_values(array_filter($rest, fn (string $arg): bool => ! str_starts_with($arg, '-')));

        if (array_intersect($flags, ['--add', '--unset', '--unset-all', '--replace-all', '--rename-section', '--remove-section', '-e', '--edit']) !== []) {
            return false;
        }

        return match ($positional[0] ?? null) {
            'set', 'unset', 'rename-section', 'remove-section', 'edit' => false,
            'get', 'list' => true,
            default => count($positional) <= 1,
        };
    }

    /**
     * The options among the arguments, with combined short flags expanded (-fu is -f and -u) and values cut off.
     *
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private static function flags(array $arguments): array
    {
        $flags = [];

        foreach ($arguments as $argument) {
            if (preg_match('/^-[a-zA-Z]{2,}$/', $argument) === 1) {
                foreach (str_split(substr($argument, 1)) as $letter) {
                    $flags[] = '-'.$letter;
                }
            } elseif (str_starts_with($argument, '-')) {
                $flags[] = explode('=', $argument, 2)[0];
            }
        }

        return $flags;
    }

    /**
     * The simple commands of a command line: split on unquoted ;, &, &&, |, ||, newlines and parentheses, and
     * at command substitutions ($(…), `…`), which run even inside double quotes.
     *
     * @return list<string>
     */
    private static function segments(string $command): array
    {
        $segments = [];
        $current = '';
        $quote = null;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];
            $next = $command[$i + 1] ?? '';

            if ($quote === "'") {
                $current .= $char;
                $quote = $char === "'" ? null : $quote;

                continue;
            }

            if ($char === '\\' && $i + 1 < $length) {
                $current .= $char.$next;
                $i++;

                continue;
            }

            if ($char === '`' || ($char === '$' && $next === '(')) {
                $segments[] = $current;
                $current = '';
                $i += $char === '$' ? 1 : 0;

                continue;
            }

            if ($quote === '"') {
                $current .= $char;
                $quote = $char === '"' ? null : $quote;

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $current .= $char;

                continue;
            }

            // & in a redirection (2>&1, &>file) does not end the command.
            $redirection = $char === '&' && (str_ends_with($current, '>') || $next === '>');

            if (! $redirection && in_array($char, [';', '&', '|', "\n", '(', ')'], true)) {
                $segments[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $segments[] = $current;

        return array_values(array_filter(array_map(trim(...), $segments), fn (string $segment): bool => $segment !== ''));
    }

    /**
     * The words of a simple command with their quotes removed.
     *
     * @return list<string>
     */
    private static function words(string $segment): array
    {
        preg_match_all('/(?:"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^\s"\']+)+/', $segment, $matches);

        return array_map(fn (string $word): string => str_replace(['"', "'"], '', $word), $matches[0]);
    }

    /**
     * The command with the text of its quoted strings left out (the quotes stay).
     */
    private static function withoutQuotedText(string $segment): string
    {
        return (string) preg_replace(['/"(?:[^"\\\\]|\\\\.)*"/s', '/\'[^\']*\'/s'], ['""', "''"], $segment);
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
