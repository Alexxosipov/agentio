<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Process\AgentComments;
use Obrazmisli\Agentio\Process\PipelineStage;
use Obrazmisli\Agentio\Process\PipelineStageResolver;
use Obrazmisli\Agentio\Process\PipelineStatus;
use Obrazmisli\Agentio\Process\ReadinessGraph;
use Obrazmisli\Agentio\YouTrack\Comment;
use Obrazmisli\Agentio\YouTrack\Issue;

/**
 * @param  list<string>  $texts
 */
function agentComments(string $issueId, array $texts): AgentComments
{
    return AgentComments::fromComments(array_map(
        fn (string $text, int $index): Comment => new Comment((string) $index, $issueId, $text),
        $texts,
        array_keys($texts),
    ));
}

function epicStatus(ReadinessGraph $graph, string $id, array $comments = []): PipelineStatus
{
    return (new PipelineStageResolver($graph))->epic($graph->get($id), agentComments($id, $comments));
}

/**
 * An epic TP-2 with story TP-3 and the given tasks (state => count).
 *
 * @param  array<string, int>  $tasks
 */
function epicGraph(string $epicState, array $tasks, string $storyState = 'In Progress', array $epicTags = []): ReadinessGraph
{
    $specs = ['TP-2' => ['Epic', $epicState, null, [], $epicTags], 'TP-3' => ['Story', $storyState, 'TP-2']];
    $number = 10;

    foreach ($tasks as $state => $count) {
        for ($i = 0; $i < $count; $i++) {
            $specs['TP-'.$number++] = ['Task', $state, 'TP-3'];
        }
    }

    return graphOf($specs);
}

it('orders and labels the stages', function () {
    expect(PipelineStage::Idea->position())->toBe(0)
        ->and(PipelineStage::Done->position())->toBe(7)
        ->and(PipelineStage::Acceptance->label())->toBe('Приёмка человеком')
        ->and(array_column(PipelineStage::toList(), 'label'))->toBe([
            'Идея', 'Требования и аналитика', 'Архитектура', 'Декомпозиция', 'Разработка', 'Ревью историй', 'Приёмка человеком', 'Готово',
        ])
        ->and(PipelineStage::earliest(PipelineStage::Acceptance, PipelineStage::Development, PipelineStage::Done))->toBe(PipelineStage::Development)
        ->and(PipelineStage::earliest())->toBeNull();
});

it('puts a finished epic in Done and an epic in Review in acceptance', function () {
    expect(epicStatus(epicGraph('Done', ['Done' => 2]), 'TP-2')->stage)->toBe(PipelineStage::Done);

    $review = epicStatus(epicGraph('Review', ['Done' => 2]), 'TP-2');

    expect($review->stage)->toBe(PipelineStage::Acceptance)
        ->and($review->note)->toBe('ждёт приёмки и слияния ветки')
        ->and($review->tasks)->toBe(['done' => 2, 'total' => 2, 'inProgress' => 0, 'blocked' => 0]);
});

it('derives the planning stage of an epic from its decisions and children', function () {
    $bare = graphOf(['TP-2' => ['Epic', 'Analysis']]);

    expect(epicStatus($bare, 'TP-2')->stage)->toBe(PipelineStage::Architecture)
        ->and(epicStatus($bare, 'TP-2', ["[AGENT:DECISION]\n**Архитектура эпика (laravel-architect)**"])->stage)->toBe(PipelineStage::Decomposition)
        ->and(epicStatus($bare, 'TP-2', ['[AGENT:DECISION] Срок: неделя'])->stage)->toBe(PipelineStage::Architecture)
        ->and(epicStatus(epicGraph('Backlog', []), 'TP-2')->stage)->toBe(PipelineStage::Decomposition);
});

it('shows development with progress while tasks are open', function () {
    $status = epicStatus(epicGraph('In Progress', ['Done' => 3, 'In Progress' => 1, 'Ready' => 2, 'Blocked' => 1]), 'TP-2');

    expect($status->stage)->toBe(PipelineStage::Development)
        ->and($status->tasks)->toBe(['done' => 3, 'total' => 7, 'inProgress' => 1, 'blocked' => 1])
        ->and($status->blocked)->toBeFalse()
        ->and($status->note)->toBeNull();
});

it('marks a ready epic that the loop can start', function () {
    $status = epicStatus(epicGraph('Ready', ['Ready' => 2]), 'TP-2');

    expect($status->stage)->toBe(PipelineStage::Development)
        ->and($status->note)->toBe('готов к запуску');
});

it('shows what a ready epic waits for', function () {
    $graph = graphOf([
        'TP-1' => ['Epic', 'In Progress'],
        'TP-2' => ['Epic', 'Ready', null, ['TP-1']],
        'TP-3' => ['Task', 'Ready', 'TP-2'],
    ]);

    $status = epicStatus($graph, 'TP-2');

    expect($status->waitingFor)->toBe(['TP-1'])
        ->and($status->note)->toBe('ждёт TP-1')
        ->and($status->toArray())->toMatchArray(['stage' => 'development', 'stageLabel' => 'Разработка', 'position' => 4, 'waitingFor' => ['TP-1']]);
});

it('is in story review when every task is done or a story is already accepted', function () {
    $allDone = epicStatus(epicGraph('In Progress', ['Done' => 4]), 'TP-2');
    $storyAccepted = epicStatus(epicGraph('In Progress', ['Done' => 2, 'In Progress' => 1], 'Review'), 'TP-2');

    expect($allDone->stage)->toBe(PipelineStage::StoryReview)
        ->and($allDone->note)->toBe('историй принято: 0 из 1')
        ->and($storyAccepted->stage)->toBe(PipelineStage::StoryReview)
        ->and($storyAccepted->note)->toBe('историй принято: 1 из 1');
});

it('is still decomposing an epic in work without tasks', function () {
    expect(epicStatus(graphOf(['TP-2' => ['Epic', 'In Progress']]), 'TP-2')->stage)->toBe(PipelineStage::Decomposition);
});

it('flags a blocked epic with the reason of the last [AGENT:BLOCKED]', function () {
    $status = epicStatus(epicGraph('Blocked', ['Done' => 1, 'Ready' => 1]), 'TP-2', [
        '[AGENT:BLOCKED] Старая причина',
        "[AGENT:START]\nowner: `h:/w/TP-2`",
        "[AGENT:BLOCKED]\n**Нужно решение человека:**\nкакой формат   хранить?\n\nПодробности ниже.",
    ]);

    expect($status->stage)->toBe(PipelineStage::Development)
        ->and($status->blocked)->toBeTrue()
        ->and($status->blockedReason)->toBe('**Нужно решение человека:** какой формат хранить?');
});

it('has no blocked reason without an [AGENT:BLOCKED] comment', function () {
    $resolver = new PipelineStageResolver(graphOf([]));

    expect($resolver->blockedReason(agentComments('TP-2', ['[AGENT:DONE] ok'])))->toBeNull()
        ->and($resolver->blockedReason(agentComments('TP-2', ['[AGENT:BLOCKED]'])))->toBeNull()
        ->and(epicStatus(graphOf(['TP-2' => ['Epic', 'Blocked']]), 'TP-2')->blockedReason)->toBeNull();
});

it('keeps an unplanned idea at the idea stage', function () {
    $graph = graphOf(['TP-1' => ['Idea', 'Backlog']]);
    $status = (new PipelineStageResolver($graph))->idea($graph->get('TP-1'), new AgentComments);

    expect($status->stage)->toBe(PipelineStage::Idea)
        ->and($status->note)->toBe('ждёт планирования');
});

it('follows the planning of an idea through analysis, architecture and decomposition', function () {
    $graph = graphOf(['TP-1' => ['Idea', 'Analysis']]);
    $resolver = new PipelineStageResolver($graph);
    $idea = $graph->get('TP-1');

    expect($resolver->idea($idea, agentComments('TP-1', ["[AGENT:START]\nowner: `pm#TP-1`", '[AGENT:DECISION] Требования зафиксированы']))->stage)->toBe(PipelineStage::Analysis)
        ->and($resolver->idea($idea, agentComments('TP-1', ['[AGENT:DECISION] Готовлю ADR по хранению']))->stage)->toBe(PipelineStage::Architecture);

    $claimedBacklog = graphOf(['TP-1' => ['Idea', 'Backlog', null, [], ['agent-claimed']]]);

    expect((new PipelineStageResolver($claimedBacklog))->idea($claimedBacklog->get('TP-1'), new AgentComments)->stage)->toBe(PipelineStage::Analysis);
});

it('takes the planning stage of an idea from the epics it already created', function () {
    $issues = [
        Issue::fromApi(apiIssue('TP-1', ['Type' => 'Idea', 'Stage' => 'Analysis'], ['relates to' => ['TP-2', 'TP-9']])),
        Issue::fromApi(apiIssue('TP-2', ['Type' => 'Epic', 'Stage' => 'Analysis'], ['relates to' => ['TP-1'], 'parent for' => ['TP-3']])),
        Issue::fromApi(apiIssue('TP-3', ['Type' => 'Story', 'Stage' => 'Backlog'], ['subtask of' => ['TP-2']])),
        Issue::fromApi(apiIssue('TP-9', ['Type' => 'Task', 'Stage' => 'Done'])),
    ];
    $graph = new ReadinessGraph($issues);
    $resolver = new PipelineStageResolver($graph);

    expect($resolver->epicsOf($graph->get('TP-1')))->toBe(['TP-2']);

    $epic = $resolver->epic($graph->get('TP-2'), new AgentComments);
    $idea = $resolver->idea($graph->get('TP-1'), new AgentComments, [$epic]);

    expect($epic->stage)->toBe(PipelineStage::Decomposition)
        ->and($idea->stage)->toBe(PipelineStage::Decomposition)
        ->and($idea->note)->toBe('идёт планирование');

    $readyEpic = new PipelineStatus('TP-2', PipelineStage::Development);

    expect($resolver->idea($graph->get('TP-1'), new AgentComments, [$readyEpic])->stage)->toBe(PipelineStage::Decomposition)
        ->and($resolver->idea($graph->get('TP-1'), new AgentComments, [new PipelineStatus('TP-2', PipelineStage::Architecture)])->stage)->toBe(PipelineStage::Architecture);
});

it('flags a blocked planning with its reason', function () {
    $graph = graphOf(['TP-1' => ['Idea', 'Blocked']]);
    $status = (new PipelineStageResolver($graph))->idea($graph->get('TP-1'), agentComments('TP-1', ['[AGENT:BLOCKED] Нет доступа к YouTrack']));

    expect($status->blocked)->toBeTrue()
        ->and($status->blockedReason)->toBe('Нет доступа к YouTrack')
        ->and($status->stage)->toBe(PipelineStage::Analysis);
});

it('follows a planned idea through the earliest of its epics', function () {
    $graph = graphOf(['TP-1' => ['Idea', 'Done']]);
    $resolver = new PipelineStageResolver($graph);
    $idea = $graph->get('TP-1');

    $epics = [
        new PipelineStatus('TP-2', PipelineStage::Acceptance, tasks: ['done' => 5, 'total' => 5, 'inProgress' => 0, 'blocked' => 0]),
        new PipelineStatus('TP-7', PipelineStage::Development, true, 'Ждёт ответа', ['done' => 1, 'total' => 4, 'inProgress' => 1, 'blocked' => 1]),
    ];

    $status = $resolver->idea($idea, new AgentComments, $epics);

    expect($status->stage)->toBe(PipelineStage::Development)
        ->and($status->tasks)->toBe(['done' => 6, 'total' => 9, 'inProgress' => 1, 'blocked' => 1])
        ->and($status->blocked)->toBeTrue()
        ->and($status->blockedReason)->toBe('TP-7: Ждёт ответа')
        ->and($status->note)->toBe('эпиков: 2');

    $single = $resolver->idea($idea, new AgentComments, [new PipelineStatus('TP-2', PipelineStage::Done)]);

    expect($single->stage)->toBe(PipelineStage::Done)
        ->and($single->blocked)->toBeFalse()
        ->and($single->note)->toBeNull()
        ->and($resolver->idea($idea, new AgentComments, [new PipelineStatus('TP-2', PipelineStage::Development, true)])->blockedReason)->toBe('TP-2: заблокирован');
});

it('treats a planned idea without epics as done or decomposed', function () {
    $graph = graphOf(['TP-1' => ['Idea', 'Done'], 'TP-5' => ['Idea', 'Ready']]);
    $resolver = new PipelineStageResolver($graph);

    expect($resolver->idea($graph->get('TP-1'), new AgentComments)->stage)->toBe(PipelineStage::Done)
        ->and($resolver->idea($graph->get('TP-5'), new AgentComments)->stage)->toBe(PipelineStage::Decomposition);
});

it('knows which issues need their comments to resolve the stage', function () {
    $graph = graphOf([
        'E1' => ['Epic', 'Analysis'], 'E2' => ['Epic', 'Blocked'], 'E3' => ['Epic', 'In Progress'], 'E4' => ['Epic', 'Done'],
        'I1' => ['Idea', 'Backlog'], 'I2' => ['Idea', 'Backlog', null, [], ['agent-claimed']], 'I3' => ['Idea', 'Analysis'], 'I4' => ['Idea', 'Done'],
    ]);

    $needs = array_keys(array_filter(array_map(PipelineStageResolver::needsComments(...), $graph->issues())));

    expect($needs)->toBe(['E1', 'E2', 'I2', 'I3']);
});
