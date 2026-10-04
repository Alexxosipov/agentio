<?php

declare(strict_types=1);

use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\Relation;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\Tag;

it('normalises a YouTrack issue', function () {
    $issue = Issue::fromApi([
        'idReadable' => 'TP-6',
        'summary' => '[TASK] Avatar columns',
        'description' => 'Details',
        'created' => 1791010361184,
        'updated' => 1791027550675,
        'resolved' => 1791010891627,
        'customFields' => [
            ['name' => 'Type', 'value' => ['name' => 'Task']],
            ['name' => 'Stage', 'value' => ['name' => 'Done']],
            ['name' => 'Assignee', 'value' => ['login' => 'a.osipov', 'fullName' => 'Alexander Osipov']],
            ['name' => 'Estimation', 'value' => ['presentation' => '1d']],
            ['name' => 'Notes', 'value' => ['text' => 'Plain text']],
            ['name' => 'Points', 'value' => 3],
            ['name' => 'Fix versions', 'value' => [['name' => '1.0'], ['name' => '1.1']]],
            ['name' => 'Subsystems', 'value' => []],
            ['name' => 'Opaque', 'value' => ['id' => '1']],
            ['name' => 'Priority', 'value' => null],
            ['value' => ['name' => 'nameless']],
        ],
        'tags' => [['id' => '6-0', 'name' => 'agent-claimed'], ['name' => 'idea'], 'junk'],
        'links' => [
            ['direction' => 'BOTH', 'linkType' => ['sourceToTarget' => 'relates to', 'targetToSource' => ''], 'issues' => [['idReadable' => 'TP-1']]],
            ['direction' => 'OUTWARD', 'linkType' => ['sourceToTarget' => 'is required for', 'targetToSource' => 'depends on'], 'issues' => [['idReadable' => 'TP-8'], ['idReadable' => 'TP-9']]],
            ['direction' => 'INWARD', 'linkType' => ['sourceToTarget' => 'is required for', 'targetToSource' => 'depends on'], 'issues' => []],
            ['direction' => 'INWARD', 'linkType' => ['sourceToTarget' => 'parent for', 'targetToSource' => 'subtask of'], 'issues' => [['idReadable' => 'TP-3']]],
            ['direction' => 'OUTWARD', 'issues' => [['idReadable' => 'TP-99']]],
        ],
    ]);

    expect($issue->id)->toBe('TP-6')
        ->and($issue->summary)->toBe('[TASK] Avatar columns')
        ->and($issue->description)->toBe('Details')
        ->and($issue->state())->toBe('Done')
        ->and($issue->type())->toBe('Task')
        ->and($issue->field('Assignee'))->toBe('a.osipov')
        ->and($issue->field('Estimation'))->toBe('1d')
        ->and($issue->field('Notes'))->toBe('Plain text')
        ->and($issue->field('Points'))->toBe('3')
        ->and($issue->field('Fix versions'))->toBe('1.0, 1.1')
        ->and($issue->field('Subsystems'))->toBeNull()
        ->and($issue->field('Opaque'))->toBeNull()
        ->and($issue->field('Priority'))->toBeNull()
        ->and($issue->field('Missing'))->toBeNull()
        ->and($issue->tags)->toBe(['agent-claimed' => '6-0', 'idea' => ''])
        ->and($issue->tagNames())->toBe(['agent-claimed', 'idea'])
        ->and($issue->relations)->toBe(['relates to' => ['TP-1'], 'is required for' => ['TP-8', 'TP-9'], 'subtask of' => ['TP-3']])
        ->and($issue->parentId())->toBe('TP-3')
        ->and($issue->related(Relation::RequiredFor))->toBe(['TP-8', 'TP-9'])
        ->and($issue->related('relates to'))->toBe(['TP-1'])
        ->and($issue->childIds())->toBe([])
        ->and($issue->dependencyIds())->toBe([])
        ->and($issue->createdAt?->getTimestampMs())->toBe(1791010361184)
        ->and($issue->updatedAt?->toIso8601String())->toBe('2026-10-03T11:39:10+00:00');
});

it('tolerates a sparse payload', function () {
    $issue = Issue::fromApi(['idReadable' => 'TP-1', 'created' => '1791010361184', 'customFields' => 'junk']);

    expect($issue->summary)->toBe('')
        ->and($issue->fields)->toBe([])
        ->and($issue->state())->toBeNull()
        ->and($issue->parentId())->toBeNull()
        ->and($issue->createdAt?->getTimestampMs())->toBe(1791010361184)
        ->and($issue->updatedAt)->toBeNull()
        ->and(Issue::fromApi([])->id)->toBe('');
});

it('answers state, type and tag questions', function () {
    $issue = Issue::fromApi(apiIssue('TP-2', ['Type' => 'Epic', 'Stage' => 'In Progress'], tags: ['agent-claimed']));

    expect($issue->hasState(State::InProgress))->toBeTrue()
        ->and($issue->hasState(State::Ready, State::Review))->toBeFalse()
        ->and($issue->hasType(IssueType::Story, IssueType::Epic))->toBeTrue()
        ->and($issue->hasType(IssueType::Task))->toBeFalse()
        ->and($issue->hasTag(Tag::Claimed))->toBeTrue()
        ->and($issue->hasTag('idea'))->toBeFalse()
        ->and($issue->isClaimed())->toBeTrue()
        ->and($issue->isIdea())->toBeFalse();
});

it('recognises ideas by type or by tag', function (array $fields, array $tags, bool $isIdea) {
    expect(Issue::fromApi(apiIssue('TP-1', $fields, tags: $tags))->isIdea())->toBe($isIdea);
})->with([
    'type Idea' => [['Type' => 'Idea'], [], true],
    'tag idea' => [['Type' => 'Task'], ['idea'], true],
    'neither' => [['Type' => 'Task'], [], false],
]);

it('serialises to an array', function () {
    $issue = Issue::fromApi(apiIssue('TP-3', ['Type' => 'Story', 'Stage' => 'Ready'], ['subtask of' => ['TP-2']], ['idea']));

    expect($issue->toArray())->toBe([
        'id' => 'TP-3',
        'summary' => 'Summary of TP-3',
        'state' => 'Ready',
        'type' => 'Story',
        'fields' => ['Type' => 'Story', 'Stage' => 'Ready'],
        'tags' => ['idea'],
        'relations' => ['subtask of' => ['TP-2']],
        'description' => null,
        'createdAt' => '2026-10-03T06:52:41+00:00',
        'updatedAt' => '2026-10-03T11:39:10+00:00',
        'resolvedAt' => null,
    ]);
});

it('normalises an issue of the get_issue tool of the MCP server', function () {
    $issue = Issue::fromMcp([
        'id' => 'TP-13',
        'summary' => '[TASK] Note page',
        'description' => 'Details',
        'parentIssue' => ['id' => 'TP-4', 'summary' => '[STORY] View a note'],
        'tags' => ['agent-claimed', ['id' => '6-1', 'name' => 'idea'], ''],
        'customFields' => ['Type' => 'Task', 'Stage' => 'In Progress', 'Priority' => 'Обычная', 'Fix versions' => ['1.0', '1.1'], 'Affected versions' => [], 'Assignee' => null],
        'createdAt' => '2026-10-03 17:13:17',
        'updatedAt' => '2026-10-03 17:42:13',
        'resolvedAt' => null,
    ], ['parent for' => [], 'depends on' => ['TP-9', 'TP-10']]);

    expect($issue->id)->toBe('TP-13')
        ->and($issue->summary)->toBe('[TASK] Note page')
        ->and($issue->description)->toBe('Details')
        ->and($issue->state())->toBe('In Progress')
        ->and($issue->field('Fix versions'))->toBe('1.0, 1.1')
        ->and($issue->field('Affected versions'))->toBeNull()
        ->and($issue->tags)->toBe(['agent-claimed' => 'agent-claimed', 'idea' => '6-1'])
        ->and($issue->isClaimed())->toBeTrue()
        ->and($issue->relations)->toBe(['depends on' => ['TP-9', 'TP-10'], 'subtask of' => ['TP-4']])
        ->and($issue->parentId())->toBe('TP-4')
        ->and($issue->childIds())->toBe([])
        ->and($issue->createdAt?->toIso8601String())->toBe('2026-10-03T17:13:17+00:00');

    expect(Issue::fromMcp([])->id)->toBe('');
});
