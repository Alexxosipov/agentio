<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Settings;

it('takes the settings from the config, then from .agentio.json, then the defaults', function () {
    $project = hostProject();
    config(['agentio.youtrack.project' => null, 'agentio.base_branch' => null, 'agentio.production_branch' => null, 'agentio.worktrees_path' => null]);
    $settings = new Settings($project);

    expect($settings->project())->toBe('TP')
        ->and($settings->baseBranch())->toBe('dev')
        ->and($settings->productionBranch())->toBe('main')
        ->and($settings->protectedBranches())->toBe(['dev', 'main', 'master'])
        ->and($settings->worktreesPath())->toBeNull();

    file_put_contents($project.'/.agentio.json', json_encode(['project' => 'AB', 'base_branch' => 'trunk', 'production_branch' => 'prod', 'merge_policy' => 'pull-request']));

    expect($settings->project())->toBe('AB')
        ->and($settings->baseBranch())->toBe('trunk')
        ->and($settings->productionBranch())->toBe('prod')
        ->and($settings->protectedBranches())->toBe(['trunk', 'prod', 'main', 'master']);

    config(['agentio.youtrack.project' => 'CD', 'agentio.base_branch' => 'develop', 'agentio.worktrees_path' => '../wt/']);

    expect($settings->project())->toBe('CD')
        ->and($settings->baseBranch())->toBe('develop')
        ->and($settings->worktreesPath())->toBe($project.'/../wt');

    config(['agentio.worktrees_path' => '/srv/worktrees/']);

    expect($settings->worktreesPath())->toBe('/srv/worktrees');
});
