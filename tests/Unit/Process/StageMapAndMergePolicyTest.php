<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Process\StageMap;
use Obrazmisli\Agentio\Runtime\MergePolicy;

it('derives Stage from State', function (?string $state, ?string $stage) {
    $map = new StageMap([
        'Backlog' => 'Backlog',
        'Analysis' => 'Backlog',
        'Ready' => 'Backlog',
        'Blocked' => 'Backlog',
        'In Progress' => 'Develop',
        'Review' => 'Review',
        'Done' => 'Done',
    ]);

    expect($map->stageFor($state))->toBe($stage);
})->with([
    ['Backlog', 'Backlog'],
    ['Analysis', 'Backlog'],
    ['Ready', 'Backlog'],
    ['Blocked', 'Backlog'],
    ['In Progress', 'Develop'],
    ['Review', 'Review'],
    ['Done', 'Done'],
    ['Unknown', null],
    [null, null],
]);

it('reads the merge policy from CLAUDE.md', function (string $markdown, ?MergePolicy $policy) {
    expect(MergePolicy::fromClaudeMarkdown($markdown))->toBe($policy);
})->with([
    'plain' => ["# Rules\n\nMERGE_POLICY: pull-request\n", MergePolicy::PullRequest],
    'backticks' => ["MERGE_POLICY: `auto-merge`\n", MergePolicy::AutoMerge],
    'first line wins' => ["MERGE_POLICY: local-branch\nMERGE_POLICY: auto-merge\n", MergePolicy::LocalBranch],
    'not at line start' => ["Set MERGE_POLICY: auto-merge\n", null],
    'unknown value' => ["MERGE_POLICY: yolo\n", null],
    'missing' => ["# Rules\n", null],
]);

it('resolves the merge policy like agent-loop.sh', function () {
    $directory = temporaryDirectory();
    file_put_contents($directory.'/CLAUDE.md', "MERGE_POLICY: pull-request\n");
    file_put_contents($directory.'/EMPTY.md', "# Nothing\n");

    expect(MergePolicy::resolve('auto-merge', $directory.'/CLAUDE.md'))->toBe(MergePolicy::AutoMerge)
        ->and(MergePolicy::resolve(null, $directory.'/CLAUDE.md'))->toBe(MergePolicy::PullRequest)
        ->and(MergePolicy::resolve('', $directory.'/CLAUDE.md'))->toBe(MergePolicy::PullRequest)
        ->and(MergePolicy::resolve(null, $directory.'/EMPTY.md'))->toBe(MergePolicy::LocalBranch)
        ->and(MergePolicy::resolve(null, $directory.'/MISSING.md'))->toBe(MergePolicy::LocalBranch)
        ->and(MergePolicy::resolve(null))->toBe(MergePolicy::LocalBranch)
        ->and(fn () => MergePolicy::resolve('yolo'))->toThrow(ValueError::class);
});
