<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Process\ReadinessGraph;
use Obrazmisli\Agentio\Process\TreeNode;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueType;

/**
 * Two epics: E1 (stories S1, S2) and E2 (story S3).
 */
function projectGraph(array $overrides = []): ReadinessGraph
{
    return graphOf([
        ...[
            'E1' => ['Epic', 'Ready'],
            'S1' => ['Story', 'Ready', 'E1'],
            'T1' => ['Task', 'Ready', 'S1'],
            'T2' => ['Task', 'Ready', 'S1', ['T1']],
            'S2' => ['Story', 'Ready', 'E1', ['X1']],
            'T3' => ['Task', 'Ready', 'S2'],
            'E2' => ['Epic', 'In Progress', null, [], ['agent-claimed']],
            'S3' => ['Story', 'In Progress', 'E2'],
            'X1' => ['Task', 'Review', 'S3'],
        ],
        ...$overrides,
    ]);
}

it('walks parents and children', function () {
    $graph = projectGraph();

    expect($graph->parentOf('T1'))->toBe('S1')
        ->and($graph->parentOf('E1'))->toBeNull()
        ->and($graph->parentOf('UNKNOWN'))->toBeNull()
        ->and($graph->ancestors('T1'))->toBe(['S1', 'E1'])
        ->and($graph->epicOf('T1'))->toBe('E1')
        ->and($graph->epicOf('E2'))->toBe('E2')
        ->and($graph->epicOf('UNKNOWN'))->toBeNull()
        ->and($graph->children('E1'))->toBe(['S1', 'S2'])
        ->and($graph->descendants('E1'))->toBe(['S1', 'S2', 'T1', 'T2', 'T3'])
        ->and(array_map(fn (Issue $issue): string => $issue->id, $graph->ofType(IssueType::Epic)))->toBe(['E1', 'E2']);
});

it('survives cyclic parent links', function () {
    $graph = graphOf([
        'A' => ['Story', 'Ready', 'B'],
        'B' => ['Story', 'Ready', 'A'],
    ]);

    expect($graph->ancestors('A'))->toBe(['B'])
        ->and($graph->descendants('A'))->toBe(['B'])
        ->and($graph->epicOf('A'))->toBeNull()
        ->and($graph->tree('A')->flatten())->toHaveCount(2);
});

it('computes readiness of tasks', function () {
    $graph = projectGraph();

    expect($graph->isTaskReady('T1'))->toBeTrue()
        ->and($graph->isTaskReady('T2'))->toBeFalse()
        ->and($graph->unmetDependencies('T2'))->toBe(['T1'])
        ->and($graph->isTaskReady('S1'))->toBeFalse()
        ->and($graph->isTaskReady('UNKNOWN'))->toBeFalse()
        ->and($graph->readyTasks('E1'))->toBe(['T1']);
});

it('treats a dependency in Review inside the same epic as met', function () {
    $graph = projectGraph(['T1' => ['Task', 'Review', 'S1']]);

    expect($graph->unmetDependencies('T2'))->toBe([])
        ->and($graph->isTaskReady('T2'))->toBeTrue();
});

it('does not accept a dependency in Review from another epic', function () {
    expect(projectGraph()->unmetDependencies('T3'))->toBe(['X1']);
});

it('inherits dependencies from the ancestors', function () {
    $graph = projectGraph(['X1' => ['Task', 'Done', 'S3']]);

    expect($graph->unmetDependencies('T3'))->toBe([])
        ->and(projectGraph()->isTaskReady('T3'))->toBeFalse()
        ->and(projectGraph(['E1' => ['Epic', 'Ready', null, ['T3']]])->unmetDependencies('T1'))->toBe(['T3']);
});

it('counts unknown dependencies as unmet', function () {
    expect(projectGraph(['T1' => ['Task', 'Ready', 'S1', ['GONE']]])->unmetDependencies('T1'))->toBe(['GONE']);
});

it('does not consider claimed tasks ready', function () {
    expect(projectGraph(['T1' => ['Task', 'Ready', 'S1', [], ['agent-claimed']]])->isTaskReady('T1'))->toBeFalse();
});

it('computes readiness of epics', function () {
    $graph = projectGraph();

    expect($graph->isEpicReady('E1'))->toBeTrue()
        ->and($graph->isEpicReady('E2'))->toBeFalse()
        ->and($graph->isEpicReady('T1'))->toBeFalse()
        ->and($graph->isEpicReady('UNKNOWN'))->toBeFalse()
        ->and($graph->readyEpics())->toBe(['E1'])
        ->and(projectGraph(['T1' => ['Task', 'In Progress', 'S1']])->isEpicReady('E1'))->toBeFalse()
        ->and(projectGraph(['E1' => ['Epic', 'Ready', null, ['X1']]])->isEpicReady('E1'))->toBeFalse()
        ->and(projectGraph(['E1' => ['Epic', 'Ready', null, [], ['agent-claimed']]])->isEpicReady('E1'))->toBeFalse();
});

it('lists ready issues waiting for dependencies', function () {
    expect(projectGraph()->waitingForDependencies())->toBe(['T2' => ['T1'], 'T3' => ['X1']]);
});

it('detects dependency cycles', function () {
    $graph = graphOf([
        'A' => ['Task', 'Ready', null, ['B']],
        'B' => ['Task', 'Ready', null, ['C']],
        'C' => ['Task', 'Ready', null, ['A']],
        'D' => ['Task', 'Ready', null, ['A']],
    ]);

    expect($graph->dependencyCycles())->toBe([['A', 'B', 'C', 'A']])
        ->and(projectGraph()->dependencyCycles())->toBe([]);
});

it('builds the epic tree with readiness', function () {
    $tree = projectGraph()->tree('E1');

    expect($tree)->toBeInstanceOf(TreeNode::class)
        ->and($tree->ready)->toBeTrue()
        ->and($tree->depth)->toBe(0)
        ->and(array_map(fn (TreeNode $node): string => $node->issue->id, $tree->flatten()))->toBe(['E1', 'S1', 'T1', 'T2', 'S2', 'T3'])
        ->and($tree->children[0]->ready)->toBeNull()
        ->and($tree->children[0]->children[1]->depth)->toBe(2)
        ->and($tree->children[0]->children[1]->unmetDependencies)->toBe(['T1']);

    $array = $tree->toArray();

    expect($array['id'])->toBe('E1')
        ->and($array['claimed'])->toBeFalse()
        ->and($array['children'][0]['parent'])->toBe('E1')
        ->and($array['children'][0]['children'][1])->toMatchArray(['id' => 'T2', 'dependsOn' => ['T1'], 'unmetDependencies' => ['T1'], 'ready' => false, 'depth' => 2, 'children' => []]);
});

it('skips unknown children in the tree', function () {
    $graph = new ReadinessGraph([Issue::fromApi(apiIssue('S1', ['Type' => 'Story'], ['parent for' => ['GONE']]))]);

    expect($graph->tree('S1')->children)->toBe([]);
});

it('counts tasks by state', function () {
    expect(projectGraph(['T3' => ['Task', null, 'S2']])->progress('E1'))->toBe(['Ready' => 2, '' => 1]);
});

it('loads unknown issues through the resolver once', function () {
    $calls = 0;
    $graph = new ReadinessGraph([], function (string $id) use (&$calls): ?Issue {
        $calls++;

        return $id === 'EXT-1' ? Issue::fromApi(apiIssue('EXT-1', ['State' => 'Done'])) : null;
    });

    expect($graph->find('EXT-1')?->state())->toBe('Done')
        ->and($graph->find('EXT-1'))->not->toBeNull()
        ->and($graph->find('GONE'))->toBeNull()
        ->and($graph->find('GONE'))->toBeNull()
        ->and($calls)->toBe(2)
        ->and(array_keys($graph->issues()))->toBe(['EXT-1']);
});

it('throws for an issue that cannot be found', function () {
    expect(fn () => projectGraph()->get('GONE'))->toThrow(OutOfBoundsException::class, 'Issue GONE is not in the graph.')
        ->and(projectGraph()->get('E1')->id)->toBe('E1');
});
