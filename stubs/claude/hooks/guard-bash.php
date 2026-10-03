<?php

declare(strict_types=1);

/*
 * PreToolUse hook for the Bash tool: a second line of defence behind the permission rules in
 * .claude/settings.json. Denies pushes to protected branches, force pushes, deleting branches other
 * than epic/*, history rewrites, destructive commands outside the project directory and access to
 * secrets. Prints a JSON permission decision on stdout and exits 0.
 */

$input = json_decode((string) stream_get_contents(STDIN), true);
$command = (string) ($input['tool_input']['command'] ?? '');
$cwd = (string) ($input['cwd'] ?? getcwd());
$project = rtrim((string) (getenv('CLAUDE_PROJECT_DIR') ?: $cwd), '/');
$protected = array_values(array_unique([getenv('BASE_BRANCH') ?: '{{base_branch}}', 'main', 'master']));
$roots = [$project];

foreach (['settings.json', 'settings.local.json'] as $settingsFile) {
    $settings = json_decode((string) @file_get_contents($project.'/.claude/'.$settingsFile), true);

    foreach ((array) ($settings['permissions']['additionalDirectories'] ?? []) as $directory) {
        $roots[] = rtrim((string) $directory, '/');
    }
}

function deny(string $reason): never
{
    echo json_encode(['hookSpecificOutput' => [
        'hookEventName' => 'PreToolUse',
        'permissionDecision' => 'deny',
        'permissionDecisionReason' => 'guard-bash: '.$reason,
    ]], JSON_UNESCAPED_UNICODE);

    exit(0);
}

/**
 * @return list<string>
 */
function segments(string $command): array
{
    $parts = preg_split('/(?:&&|\|\||;|\||\n|\$\(|`)/', $command) ?: [];

    return array_values(array_filter(array_map(trim(...), $parts), fn (string $part): bool => $part !== ''));
}

/**
 * @return list<string>
 */
function words(string $segment): array
{
    preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|\S+/', $segment, $matches);

    return array_map(fn (string $word): string => trim($word, '"\''), $matches[0]);
}

/**
 * @param  list<string>  $roots
 */
function isInside(string $path, array $roots): bool
{
    foreach ($roots as $root) {
        if ($path === $root || str_starts_with($path, $root.'/')) {
            return true;
        }
    }

    return false;
}

function resolvePath(string $path, string $cwd): string
{
    $home = (string) getenv('HOME');
    $path = preg_replace('#^~(?=/|$)#', $home, $path) ?? $path;
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

if (preg_match('/\.env\.(production|prod|staging)\b|\.claude\.json|\.ssh\/|id_(rsa|ed25519)|\.config\/gh\//i', $command) === 1) {
    deny('access to secrets or production environment files is not allowed.');
}

if (preg_match('/\$\{?(YOUTRACK_TOKEN|ANTHROPIC_API_KEY|ANTHROPIC_AUTH_TOKEN)\b|\bprintenv\b|(^|[;&|]\s*)(env|set|export -p)\s*($|[;&|])|\/proc\/[0-9a-z]+\/environ/', $command) === 1) {
    deny('reading credentials from the environment is not allowed; scripts/yt.php reads them itself.');
}

if (preg_match('/^\s*sudo\b|\bmkfs\b|\bdd\s+if=|\bchmod\s+-R\s+777\b|:\(\)\s*\{/', $command) === 1) {
    deny('privileged or destructive system command.');
}

foreach (segments($command) as $segment) {
    $words = words($segment);

    while ($words !== [] && preg_match('/^[A-Z_][A-Z0-9_]*=/', $words[0]) === 1) {
        array_shift($words);
    }

    if ($words === []) {
        continue;
    }

    if ($words[0] === 'git') {
        $args = array_slice($words, 1);

        while ($args !== [] && in_array($args[0], ['-C', '-c', '--git-dir', '--work-tree'], true)) {
            if ($args[0] === '-C' && isset($args[1]) && ! isInside(resolvePath($args[1], $cwd), $roots)) {
                deny('git -C outside of the project directory.');
            }

            $args = array_slice($args, 2);
        }

        $sub = $args[0] ?? '';
        $rest = array_slice($args, 1);

        if ($sub === 'push') {
            foreach ($rest as $arg) {
                if (preg_match('/^(-f|--force.*|--mirror|--all|--prune|--tags)$/', $arg) === 1 || str_starts_with($arg, '+')) {
                    deny("force/mirror push ({$arg}) is not allowed.");
                }
            }

            $refs = array_values(array_filter($rest, fn (string $arg): bool => ! str_starts_with($arg, '-')));
            $refspecs = array_slice($refs, 1);
            $deleting = in_array('--delete', $rest, true) || in_array('-d', $rest, true);

            if ($refspecs === []) {
                $current = trim((string) shell_exec('git -C '.escapeshellarg($cwd).' branch --show-current 2>/dev/null'));
                $refspecs = [$current];
            }

            foreach ($refspecs as $refspec) {
                $target = str_contains($refspec, ':') ? mb_substr($refspec, mb_strpos($refspec, ':') + 1) : $refspec;
                $target = preg_replace('#^refs/heads/#', '', $target) ?? $target;

                if ($target === '' || str_starts_with($refspec, ':')) {
                    $deleting = true;
                    $target = ltrim($refspec, ':');
                }

                if (in_array($target, $protected, true) || $target === 'HEAD') {
                    deny("pushing to protected branch '{$target}' is not allowed; humans merge into {$protected[0]}.");
                }

                if (! str_starts_with($target, 'epic/')) {
                    deny("only epic/* branches may be pushed or deleted (got '{$target}').");
                }
            }

            continue;
        }

        if ($sub === 'branch' && array_intersect($rest, ['-d', '-D', '--delete', '-m', '-M', '--move', '-f', '--force']) !== []) {
            foreach (array_filter($rest, fn (string $arg): bool => ! str_starts_with($arg, '-')) as $name) {
                if (! str_starts_with($name, 'epic/')) {
                    deny("deleting, renaming or force-moving branch '{$name}' is not allowed; only epic/* branches.");
                }
            }

            continue;
        }

        if ($sub === 'reset' && in_array('--hard', $rest, true)) {
            deny('git reset --hard is not allowed (it can destroy work of parallel agents).');
        }

        if ($sub === 'clean' || ($sub === 'stash' && ! in_array($rest[0] ?? '', ['list', 'show'], true))) {
            deny("git {$sub} is not allowed (it can destroy work of parallel agents).");
        }

        if (in_array($sub, ['checkout', 'restore'], true) && array_intersect($rest, ['.', ':/', '*']) !== []) {
            deny("git {$sub} of the whole tree is not allowed; restore specific files.");
        }

        if (in_array($sub, ['checkout', 'switch'], true) && array_intersect($rest, $protected) !== []) {
            deny("switching to '{$protected[0]}' inside an agent worktree is not allowed.");
        }

        if (in_array($sub, ['rebase', 'filter-branch', 'filter-repo', 'replace', 'update-ref'], true)) {
            deny("history rewriting (git {$sub}) is not allowed.");
        }

        if ($sub === 'worktree' && in_array($rest[0] ?? '', ['remove', 'prune'], true)) {
            deny('worktrees are managed by scripts/agent-loop.sh and scripts/epic-worktree.sh.');
        }

        continue;
    }

    if (in_array($words[0], ['rm', 'rmdir', 'shred', 'truncate', 'mv', 'chmod', 'chown', 'find'], true)) {
        $isDestructive = $words[0] !== 'find' || array_intersect(array_slice($words, 1), ['-delete', '-exec', '-execdir']) !== [];

        if (! $isDestructive) {
            continue;
        }

        foreach (array_slice($words, 1) as $arg) {
            if (str_starts_with($arg, '-') || in_array($arg, ['{}', '\\;', ';', '+'], true)) {
                continue;
            }

            $path = resolvePath($arg, $cwd);

            if ((in_array($path, $roots, true) && $words[0] !== 'find') || ! isInside($path, $roots)) {
                deny("{$words[0]} on '{$arg}' outside of the project directory ({$project}) is not allowed.");
            }

            if (preg_match('#/\.git(/|$)#', $path) === 1) {
                deny("{$words[0]} inside .git is not allowed.");
            }
        }
    }
}

exit(0);
