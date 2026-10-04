<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Runtime\MergePolicy;
use Obrazmisli\Agentio\Settings;

it('takes the settings from the config, then from .agentio.json, then the defaults', function () {
    $project = hostProject();
    config(['agentio.youtrack.project' => null, 'agentio.base_branch' => null, 'agentio.merge_policy' => null, 'agentio.worktrees_path' => null]);
    $settings = new Settings($project);

    expect($settings->project())->toBe('TP')
        ->and($settings->baseBranch())->toBe('main')
        ->and($settings->mergePolicy())->toBe(MergePolicy::LocalBranch)
        ->and($settings->worktreesPath())->toBeNull();

    file_put_contents($project.'/.agentio.json', json_encode(['project' => 'AB', 'base_branch' => 'trunk', 'merge_policy' => 'pull-request']));

    expect($settings->project())->toBe('AB')
        ->and($settings->baseBranch())->toBe('trunk')
        ->and($settings->mergePolicy())->toBe(MergePolicy::PullRequest);

    config(['agentio.youtrack.project' => 'CD', 'agentio.base_branch' => 'develop', 'agentio.merge_policy' => 'auto-merge', 'agentio.worktrees_path' => '../wt/']);

    expect($settings->project())->toBe('CD')
        ->and($settings->baseBranch())->toBe('develop')
        ->and($settings->mergePolicy())->toBe(MergePolicy::AutoMerge)
        ->and($settings->worktreesPath())->toBe($project.'/../wt');

    config(['agentio.worktrees_path' => '/srv/worktrees/', 'agentio.merge_policy' => 'yolo']);

    expect($settings->worktreesPath())->toBe('/srv/worktrees')
        ->and(fn () => $settings->mergePolicy())->toThrow(ValueError::class);
});
