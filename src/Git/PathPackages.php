<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Git;

/**
 * The Composer packages a lock file installs from a path repository (e.g. a copy of agentio in packages/agentio)
 * whose directory is missing: `composer install` fails on them in an epic worktree, which has only what the
 * branch commits. A project that moved such a package to a Composer repository on one branch keeps the old
 * lock file on the others.
 */
final readonly class PathPackages
{
    /**
     * @param  string  $lock  The content of composer.lock
     * @param  callable(string): bool  $exists  Whether a path of the lock file exists; a relative path is relative to the project
     * @return array<string, string> Package name => its missing path
     */
    public static function missing(string $lock, callable $exists): array
    {
        $decoded = json_decode($lock, true);
        $missing = [];

        if (! is_array($decoded)) {
            return [];
        }

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($decoded[$section] ?? null) ? $decoded[$section] : [] as $package) {
                $dist = is_array($package) && is_array($package['dist'] ?? null) ? $package['dist'] : [];

                if (($dist['type'] ?? null) !== 'path' || ! is_string($dist['url'] ?? null) || ! is_string($package['name'] ?? null)) {
                    continue;
                }

                $path = rtrim($dist['url'], '/');

                if (! $exists($path)) {
                    $missing[$package['name']] = $path;
                }
            }
        }

        return $missing;
    }

    /**
     * The path packages of a branch's composer.lock missing from that branch (a relative path) or from this
     * machine (an absolute one); empty when the branch has no composer.lock.
     *
     * @return array<string, string>
     */
    public static function missingOnBranch(Git $git, string $branch): array
    {
        $lock = $git->output('show', $branch.':composer.lock');

        if ($lock === null) {
            return [];
        }

        return self::missing($lock, fn (string $path): bool => str_starts_with($path, '/')
            ? is_dir($path)
            : $git->run('cat-file', '-e', $branch.':'.preg_replace('#^\./#', '', $path))->isSuccessful());
    }

    /**
     * The path packages of the composer.lock in a directory missing on disk (relative to the directory).
     *
     * @return array<string, string>
     */
    public static function missingIn(string $directory): array
    {
        $lock = @file_get_contents($directory.'/composer.lock');

        if ($lock === false) {
            return [];
        }

        return self::missing($lock, fn (string $path): bool => is_dir(str_starts_with($path, '/') ? $path : $directory.'/'.$path));
    }
}
