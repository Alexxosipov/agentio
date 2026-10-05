<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Git\Git;
use Obrazmisli\Agentio\Git\PathPackages;
use Symfony\Component\Process\Process;

/**
 * A composer.lock with the given packages: name => dist (type, url).
 *
 * @param  array<string, array{type: string, url: string}>  $packages
 */
function composerLock(array $packages, array $dev = []): string
{
    $section = fn (array $packages): array => array_map(fn (string $name, array $dist): array => ['name' => $name, 'version' => 'dev-main', 'dist' => $dist], array_keys($packages), $packages);

    return (string) json_encode(['packages' => $section($packages), 'packages-dev' => $section($dev)]);
}

it('finds the packages installed from a path that is missing', function () {
    $lock = composerLock(
        ['laravel/framework' => ['type' => 'zip', 'url' => 'https://example.com/framework.zip'], 'acme/local' => ['type' => 'path', 'url' => 'packages/local']],
        ['alexxosipov/agentio' => ['type' => 'path', 'url' => './packages/agentio/']],
    );

    expect(PathPackages::missing($lock, fn (string $path): bool => $path === 'packages/local'))->toBe(['alexxosipov/agentio' => './packages/agentio'])
        ->and(PathPackages::missing('not json', fn (): bool => false))->toBe([]);
});

it('checks the paths of a directory on disk', function () {
    $directory = temporaryDirectory();
    mkdir($directory.'/packages/local', 0777, true);
    file_put_contents($directory.'/composer.lock', composerLock(['acme/local' => ['type' => 'path', 'url' => 'packages/local'], 'alexxosipov/agentio' => ['type' => 'path', 'url' => 'packages/agentio']]));

    expect(PathPackages::missingIn($directory))->toBe(['alexxosipov/agentio' => 'packages/agentio'])
        ->and(PathPackages::missingIn($directory.'/packages'))->toBe([]);
});

it('checks the paths of a branch in the branch itself', function () {
    $directory = temporaryDirectory();
    $git = fn (string ...$arguments) => (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.com', ...$arguments], $directory))->mustRun();
    $git('init', '-q', '-b', 'main');
    mkdir($directory.'/packages/agentio', 0777, true);
    file_put_contents($directory.'/packages/agentio/composer.json', '{}');
    file_put_contents($directory.'/composer.lock', composerLock(['alexxosipov/agentio' => ['type' => 'path', 'url' => 'packages/agentio']]));
    $git('add', '-A');
    $git('commit', '-q', '-m', 'path repository');
    $git('branch', 'dev');
    $git('rm', '-q', '-r', 'packages');
    $git('commit', '-q', '-m', 'agentio from its repository, lock not updated');

    // The working copy of main still has the directory (an untracked copy): only the branch counts.
    mkdir($directory.'/packages/agentio', 0777, true);

    expect(PathPackages::missingOnBranch(new Git($directory), 'refs/heads/dev'))->toBe([])
        ->and(PathPackages::missingOnBranch(new Git($directory), 'refs/heads/main'))->toBe(['alexxosipov/agentio' => 'packages/agentio'])
        ->and(PathPackages::missingOnBranch(new Git($directory), 'refs/heads/nope'))->toBe([]);
});
