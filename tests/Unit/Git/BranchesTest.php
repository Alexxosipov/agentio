<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Git\Branches;
use Obrazmisli\Agentio\Git\Git;
use Obrazmisli\Agentio\Install\BranchSetup;
use Obrazmisli\Agentio\Install\SetupStatus;
use Symfony\Component\Process\Process;

function repositoryWith(string ...$branches): Git
{
    $directory = temporaryDirectory();

    foreach ([['init', '-q', '-b', 'main'], ['-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-q', '--allow-empty', '-m', 'init']] as $git) {
        (new Process(['git', ...$git], $directory))->mustRun();
    }

    foreach ($branches as $branch) {
        (new Process(['git', 'branch', $branch], $directory))->mustRun();
    }

    return new Git($directory);
}

it('names the branch of an issue after its id', function () {
    expect(Branches::forIssue('TP-12'))->toBe('TP-12')
        ->and(fn () => Branches::forIssue('tp-12'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Branches::forIssue('TP-12; rm -rf /'))->toThrow(InvalidArgumentException::class);
});

it('tells the branches agents may push and delete', function (string $branch, bool $work) {
    expect(Branches::isWorkBranch($branch))->toBe($work);
})->with([
    ['TP-12', true],
    ['AB_C-1', true],
    ['epic/TP-12-profile-page', true],
    ['dev', false],
    ['main', false],
    ['feature/TP-12', false],
    ['TP-12-x', false],
    ['epic/x', false],
]);

it('finds the branch of an issue, also one of an earlier agentio version', function () {
    $git = repositoryWith('TP-12', 'epic/TP-7-avatar', 'epic/TP-70-other');

    expect(Branches::find($git, 'TP-12'))->toBe('TP-12')
        ->and(Branches::find($git, 'TP-7'))->toBe('epic/TP-7-avatar')
        ->and(Branches::find($git, 'TP-8'))->toBeNull()
        ->and(Branches::find($git, 'not an id'))->toBeNull()
        ->and(Branches::resolve($git, 'TP-8'))->toBe('TP-8')
        ->and(Branches::resolve($git, 'TP-7'))->toBe('epic/TP-7-avatar');
});

it('creates the development branch from the production branch, and only locally', function () {
    $git = repositoryWith();

    $plan = (new BranchSetup($git))->ensure('dev', 'main', dryRun: true);

    expect(array_map(fn ($action): array => [$action->name, $action->status], $plan))->toBe([['main', SetupStatus::Exists], ['dev', SetupStatus::Create]])
        ->and($git->branchExists('dev'))->toBeFalse();

    $actions = (new BranchSetup($git))->ensure('dev', 'main');

    expect($actions[1]->status)->toBe(SetupStatus::Create)
        ->and($actions[1]->detail)->toBe('development, created from main')
        ->and($git->branchExists('dev'))->toBeTrue()
        ->and($git->currentBranch())->toBe('main')
        ->and(array_map(fn ($action): SetupStatus => $action->status, (new BranchSetup($git))->ensure('dev', 'main')))->toBe([SetupStatus::Exists, SetupStatus::Exists]);
});

it('creates a missing production branch from the current commit and reports branches origin lacks', function () {
    $git = repositoryWith();
    $git->run('remote', 'add', 'origin', 'https://example.com/repo.git');

    $actions = (new BranchSetup($git))->ensure('dev', 'prod');

    expect(array_map(fn ($action): array => [$action->name, $action->status->value, $action->detail], $actions))->toBe([
        ['prod', 'create', 'production, created from the current commit'],
        ['dev', 'create', 'development, created from prod'],
        ['prod', 'warning', 'not on origin yet: git push -u origin prod'],
        ['dev', 'warning', 'not on origin yet: git push -u origin dev'],
    ]);
});

it('does not create branches in a repository without commits', function () {
    $directory = temporaryDirectory();
    (new Process(['git', 'init', '-q', '-b', 'main'], $directory))->mustRun();

    expect((new BranchSetup(new Git($directory)))->ensure('dev', 'main')[0]->status)->toBe(SetupStatus::Warning);
});
