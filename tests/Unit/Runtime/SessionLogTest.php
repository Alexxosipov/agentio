<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Runtime\SessionEvent;
use Obrazmisli\Agentio\Runtime\SessionEventType;
use Obrazmisli\Agentio\Runtime\SessionLog;

function sessionLog(): SessionLog
{
    return new SessionLog(fixture('session.log'), 'Asia/Shanghai');
}

/**
 * @param  list<SessionEvent>  $events
 * @return list<string>
 */
function eventTypes(array $events): array
{
    return array_map(fn (SessionEvent $event): string => $event->type->value, $events);
}

it('parses the meaningful events of a stream-json log', function () {
    $events = sessionLog()->read()->events;

    expect(eventTypes($events))->toBe([
        'session_start',
        'init',
        'tool_use',
        'tool_use',
        'text',
        'tool_use',
        'subagent',
        'tool_use',
        'text',
        'permission_denied',
        'tool_error',
        'text',
        'task_finished',
        'result',
    ]);
});

it('reads the session header written by the loop', function () {
    $header = sessionLog()->read()->events[0];

    expect($header->text)->toBe('/work-epic TP-2 in /srv/worktrees/TP-2')
        ->and($header->offset)->toBe(0)
        ->and($header->time?->toIso8601String())->toBe('2026-10-03T14:57:38+08:00');
});

it('reads the session init', function () {
    $init = sessionLog()->read()->events[1];

    expect($init->type)->toBe(SessionEventType::Init)
        ->and($init->model)->toBe('claude-opus-5-5')
        ->and($init->text)->toBe('/srv/worktrees/TP-2')
        ->and($init->sessionId)->toBe('85967167-de85-4ad9-bcb9-046e1ffe7e86');
});

it('summarises tool calls', function () {
    [, , $skill, $bash, , $comment] = sessionLog()->read()->events;

    expect($skill->tool)->toBe('Skill')
        ->and($skill->text)->toBe('youtrack-workflow')
        ->and($skill->detail)->toBeNull()
        ->and($skill->time?->toIso8601String())->toBe('2026-10-03T06:57:41+00:00')
        ->and($bash->tool)->toBe('Bash')
        ->and($bash->text)->toBe('Check branch and worktree path')
        ->and($bash->detail)->toBe('git branch --show-current; git rev-parse --show-toplevel')
        ->and($bash->inSubagent)->toBeFalse()
        ->and($comment->tool)->toBe('mcp__youtrack__add_issue_comment')
        ->and($comment->text)->toStartWith('TP-2 [AGENT:START] **План оркестрации**');
});

it('recognises subagents and the events inside them', function () {
    $events = sessionLog()->read()->events;
    $spawn = $events[6];
    $inside = $events[7];

    expect($spawn->type)->toBe(SessionEventType::Subagent)
        ->and($spawn->tool)->toBe('Agent')
        ->and($spawn->subagent)->toBe('task-developer')
        ->and($spawn->text)->toBe('TP-6 avatar columns')
        ->and($inside->inSubagent)->toBeTrue()
        ->and($inside->subagent)->toBe('TP-6 avatar columns')
        ->and($inside->detail)->toBe('php scripts/yt.php context TP-6')
        ->and($events[8]->inSubagent)->toBeFalse()
        ->and($events[11]->subagent)->toBe('TP-7 CreateSquareWebpImage');
});

it('reports denials, tool errors and finished background tasks', function () {
    $events = sessionLog()->read()->events;

    expect($events[9]->toArray())->toMatchArray([
        'type' => 'permission_denied',
        'tool' => 'Bash',
        'text' => "Permission to use Bash has been denied because Claude Code is running in don't ask mode",
        'inSubagent' => true,
        'isError' => true,
    ])
        ->and($events[10]->type)->toBe(SessionEventType::ToolError)
        ->and($events[10]->isError)->toBeTrue()
        ->and($events[12]->toArray())->toMatchArray(['type' => 'task_finished', 'status' => 'failed', 'text' => 'Run model, arch, middleware and feature tests', 'isError' => true]);
});

it('reads the result with cost and duration', function () {
    $result = sessionLog()->lastResult();

    expect($result?->type)->toBe(SessionEventType::Result)
        ->and($result?->status)->toBe('success')
        ->and($result?->costUsd)->toBe(4.450337399999998)
        ->and($result?->durationMs)->toBe(25955)
        ->and($result?->turns)->toBe(15)
        ->and($result?->isError)->toBeFalse()
        ->and($result?->text)->toStartWith('Первая волна идёт')
        ->and($result?->time)->toBeNull();
});

it('gives events without a timestamp the time of the event before them', function () {
    $events = sessionLog()->read()->events;

    expect($events[1]->time?->toIso8601String())->toBe('2026-10-03T14:57:38+08:00')
        ->and($events[13]->time)->toEqual($events[11]->time);
});

it('pages forward from an offset', function () {
    $log = sessionLog();
    $first = $log->read(0, 3);
    $second = $log->read($first->nextOffset, 100);

    expect(eventTypes($first->events))->toBe(['session_start', 'init', 'tool_use'])
        ->and($first->hasMore)->toBeTrue()
        ->and($second->events[0]->type)->toBe(SessionEventType::ToolUse)
        ->and($second->events[0]->offset)->toBe($first->nextOffset + strlen((string) file(fixture('session.log'))[3]))
        ->and($second->nextOffset)->toBe($log->size())
        ->and($second->hasMore)->toBeFalse()
        ->and($log->read($log->size())->events)->toBe([]);
});

it('limits the bytes read forward', function () {
    $page = sessionLog()->read(0, 100, 10);

    expect(eventTypes($page->events))->toBe(['session_start'])
        ->and($page->hasMore)->toBeTrue();
});

it('leaves a line that is still being written for the next read', function () {
    $path = temporaryDirectory().'/TP-9.log';
    $line = '{"type":"assistant","timestamp":"2026-10-03T07:00:00Z","message":{"content":[{"type":"text","text":"Working"}]}}';
    file_put_contents($path, "===== 2026-10-03 15:00:00 /work-epic TP-9 in /srv =====\n".$line);

    $log = new SessionLog($path);
    $page = $log->read();

    expect(eventTypes($page->events))->toBe(['session_start'])
        ->and($page->hasMore)->toBeFalse();

    file_put_contents($path, "\n", FILE_APPEND);

    expect(eventTypes($log->read($page->nextOffset)->events))->toBe(['text'])
        ->and($log->tail(10))->toHaveCount(2);
});

it('restarts from the beginning when the log was truncated', function () {
    $path = temporaryDirectory().'/TP-9.log';
    file_put_contents($path, "===== 2026-10-03 15:00:00 /plan TP-9 =====\n");

    expect((new SessionLog($path))->read(10_000)->events)->toHaveCount(1)
        ->and((new SessionLog($path))->read(-1)->events)->toHaveCount(1);
});

it('returns the last events oldest first', function () {
    $tail = sessionLog()->tail(3);

    expect(eventTypes($tail))->toBe(['text', 'task_finished', 'result'])
        ->and($tail[2]->time)->toEqual($tail[0]->time)
        ->and(sessionLog()->tail(0))->toBe([])
        ->and(sessionLog()->tail(1000))->toHaveCount(14);
});

it('handles missing and empty logs', function () {
    $directory = temporaryDirectory();
    touch($directory.'/empty.log');
    $missing = new SessionLog($directory.'/missing.log');
    $empty = new SessionLog($directory.'/empty.log');

    expect($missing->exists())->toBeFalse()
        ->and($missing->size())->toBe(0)
        ->and($missing->modifiedAt())->toBeNull()
        ->and($missing->read()->events)->toBe([])
        ->and($missing->tail())->toBe([])
        ->and($missing->lastResult())->toBeNull()
        ->and($empty->exists())->toBeTrue()
        ->and($empty->modifiedAt())->not->toBeNull()
        ->and($empty->read()->toArray())->toBe(['events' => [], 'nextOffset' => 0, 'hasMore' => false])
        ->and($empty->lastResult())->toBeNull()
        ->and(sessionLog()->path())->toBe(fixture('session.log'));
});

it('does not find a result that is not there', function () {
    $path = temporaryDirectory().'/TP-9.log';
    file_put_contents($path, '{"type":"result","subtype":"success"'."\n".'{"type":"assistant","message":{"content":[]}}'."\n");

    expect((new SessionLog($path))->lastResult())->toBeNull();
});

it('parses individual lines defensively', function (string $line, array $expected) {
    expect(array_map(fn (SessionEvent $event): array => [$event->type->value, $event->text], SessionLog::parseLine($line)))->toBe($expected);
})->with([
    'blank' => ['   ', []],
    'broken json' => ['{"type":', []],
    'json scalar' => ['{}', []],
    'stderr noise' => ['Error: connection reset', [['text', 'Error: connection reset']]],
    'header without time' => ['===== manual run =====', [['session_start', 'manual run']]],
    'rate limit' => ['{"type":"rate_limit_event"}', []],
    'other system event' => ['{"type":"system","subtype":"task_progress"}', []],
    'thinking only' => ['{"type":"assistant","message":{"content":[{"type":"thinking","thinking":""}]}}', []],
    'empty text' => ['{"type":"assistant","message":{"content":[{"type":"text","text":"  "}]}}', []],
    'no content' => ['{"type":"assistant","message":"junk"}', []],
    'several blocks' => [
        '{"type":"assistant","message":{"content":[{"type":"text","text":"Reading"},{"type":"tool_use","name":"Read","input":{"file_path":"/srv/app/a.php"}}]}}',
        [['text', 'Reading'], ['tool_use', '/srv/app/a.php']],
    ],
    'tool without input' => ['{"type":"assistant","message":{"content":[{"type":"tool_use","name":"TaskList","input":{}}]}}', [['tool_use', '']]],
    'tool with unknown input' => [
        '{"type":"assistant","message":{"content":[{"type":"tool_use","name":"mcp__youtrack__update_issue","input":{"issueId":"TP-3","customFields":{"State":"Done"}}}]}}',
        [['tool_use', 'TP-3 {"customFields":{"State":"Done"}}']],
    ],
    'tool with only an entity' => [
        '{"type":"assistant","message":{"content":[{"type":"tool_use","name":"mcp__youtrack__get_issue","input":{"issueId":"TP-17"}}]}}',
        [['tool_use', 'TP-17']],
    ],
    'entity already in the summary' => [
        '{"type":"assistant","message":{"content":[{"type":"tool_use","name":"mcp__youtrack__search_issues","input":{"project":"TP","query":"project: TP tag: idea"}}]}}',
        [['tool_use', 'project: TP tag: idea']],
    ],
    'task tool with prompt only' => [
        '{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Task","input":{"prompt":"Review STORY TP-4"}}]}}',
        [['subagent', 'Review STORY TP-4']],
    ],
    'tool error with structured content' => [
        '{"type":"user","message":{"content":[{"type":"tool_result","is_error":true,"content":[{"type":"text","text":"Exit code 1"}]}]}}',
        [['tool_error', '[{"type":"text","text":"Exit code 1"}]']],
    ],
    'successful tool result' => ['{"type":"user","message":{"content":[{"type":"tool_result","content":"ok"}]}}', []],
    'bad timestamp' => ['{"type":"assistant","timestamp":"not a date","message":{"content":[{"type":"text","text":"Hi"}]}}', [['text', 'Hi']]],
    'minimal result' => ['{"type":"result"}', [['result', '']]],
    'minimal init' => ['{"type":"system","subtype":"init"}', [['init', '']]],
    'minimal denial' => ['{"type":"system","subtype":"permission_denied"}', [['permission_denied', '']]],
    'minimal notification' => ['{"type":"system","subtype":"task_notification"}', [['task_finished', '']]],
]);

it('marks the subagent type when a subagent has no description', function () {
    $event = SessionLog::parseLine('{"type":"assistant","parent_tool_use_id":"toolu_1","subagent_type":"story-reviewer","message":{"content":[{"type":"text","text":"Reviewing"}]}}')[0];

    expect($event->subagent)->toBe('story-reviewer')
        ->and($event->inSubagent)->toBeTrue()
        ->and(SessionLog::parseLine('{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Agent","input":{}}]}}')[0]->subagent)->toBe('general-purpose');
});

it('shortens long texts', function () {
    $event = SessionLog::parseLine(json_encode(['type' => 'assistant', 'message' => ['content' => [
        ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => str_repeat('echo hi; ', 100)]],
    ]]]) ?: '')[0];

    expect(mb_strlen($event->text))->toBeLessThanOrEqual(301)
        ->and($event->text)->toEndWith('…');
});
