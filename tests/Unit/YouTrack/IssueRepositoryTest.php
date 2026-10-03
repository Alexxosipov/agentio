<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueRepository;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
});

function repository(): IssueRepository
{
    return new IssueRepository(new Client('https://yt.example.com', 'secret', 30, 0), 'TP');
}

it('searches the project and pages through the results', function () {
    $firstPage = array_map(fn (int $number): array => apiIssue('TP-'.$number), range(1, Client::PAGE_SIZE));

    Http::fake(['yt.example.com/api/issues?*' => Http::sequence()
        ->push($firstPage)
        ->push([apiIssue('TP-201')])]);

    $issues = repository()->projectIssues('Type: Epic');

    expect($issues)->toHaveCount(201)
        ->and($issues[200]->id)->toBe('TP-201')
        ->and(repository()->project())->toBe('TP');

    Http::assertSent(fn (Request $request): bool => $request['query'] === 'project: TP Type: Epic');
});

it('finds an issue with its description', function () {
    Http::fake(['yt.example.com/api/issues/TP-6?*' => Http::response([...apiIssue('TP-6'), 'description' => 'Body'])]);

    expect(repository()->find('TP-6'))
        ->toBeInstanceOf(Issue::class)
        ->description->toBe('Body');
});

it('lists ideas waiting for planning', function () {
    Http::fake(['yt.example.com/api/issues?*' => Http::response([
        apiIssue('TP-1', ['Type' => 'Idea', 'State' => 'Backlog']),
        apiIssue('TP-2', ['Type' => 'Task', 'State' => 'Backlog'], tags: ['idea']),
        apiIssue('TP-3', ['Type' => 'Idea', 'State' => 'Backlog'], tags: ['agent-claimed']),
        apiIssue('TP-4', ['Type' => 'Task', 'State' => 'Backlog']),
        apiIssue('TP-5', ['Type' => 'Idea', 'State' => 'Analysis', 'Stage' => 'Backlog']),
    ])]);

    expect(array_map(fn (Issue $issue): string => $issue->id, repository()->ideas()))->toBe(['TP-1', 'TP-2']);

    Http::assertSent(fn (Request $request): bool => $request['query'] === 'project: TP State: Backlog');
});

it('lists claimed epics', function () {
    Http::fake(['yt.example.com/api/issues?*' => Http::response([
        apiIssue('TP-2', ['Type' => 'Epic', 'State' => 'In Progress'], tags: ['agent-claimed']),
        apiIssue('TP-9', ['Type' => 'Task', 'State' => 'In Progress'], tags: ['agent-claimed']),
    ])]);

    expect(array_map(fn (Issue $issue): string => $issue->id, repository()->claimedEpics()))->toBe(['TP-2']);

    Http::assertSent(fn (Request $request): bool => $request['query'] === 'project: TP Type: Epic tag: agent-claimed');
});

it('builds the readiness graph and loads issues outside the project on demand', function () {
    Http::fake([
        'yt.example.com/api/issues?*' => Http::response([
            apiIssue('TP-2', ['Type' => 'Epic', 'State' => 'Ready'], ['parent for' => ['TP-3'], 'depends on' => ['EXT-1']]),
            apiIssue('TP-3', ['Type' => 'Story', 'State' => 'Ready'], ['subtask of' => ['TP-2'], 'parent for' => ['TP-4']]),
            apiIssue('TP-4', ['Type' => 'Task', 'State' => 'Ready'], ['subtask of' => ['TP-3'], 'depends on' => ['GONE-1']]),
        ]),
        'yt.example.com/api/issues/EXT-1?*' => Http::response(apiIssue('EXT-1', ['Type' => 'Task', 'State' => 'Done'])),
        'yt.example.com/api/issues/GONE-1?*' => Http::response(['error_description' => 'not found'], 404),
    ]);

    $graph = repository()->graph();

    expect($graph->unmetDependencies('TP-4'))->toBe(['GONE-1'])
        ->and($graph->find('EXT-1')?->state())->toBe('Done')
        ->and($graph->find('GONE-1'))->toBeNull();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://yt.example.com/api/issues/EXT-1?')
        && $request['fields'] === Client::ISSUE_FIELDS);
});

it('does not hide YouTrack failures while loading the graph', function () {
    Http::fake([
        'yt.example.com/api/issues?*' => Http::response([
            apiIssue('TP-4', ['Type' => 'Task', 'State' => 'Ready'], ['depends on' => ['EXT-1']]),
        ]),
        'yt.example.com/api/issues/EXT-1?*' => Http::response('boom', 500),
    ]);

    expect(fn () => repository()->graph()->unmetDependencies('TP-4'))->toThrow(YouTrackException::class, 'HTTP 500');
});

it('reads comments and agent comments of an issue', function () {
    Http::fake(['yt.example.com/api/issues/TP-2/comments*' => Http::response([
        ['id' => '7-1', 'text' => 'A human note', 'created' => 1791010000000, 'author' => ['login' => 'a.osipov', 'fullName' => 'Alexander Osipov']],
        ['id' => '7-2', 'text' => "[AGENT:START]\nowner: `host:/srv/worktrees/TP-2`\nbranch: `epic/TP-2-x`", 'created' => 1791010100000, 'author' => ['login' => 'a.osipov']],
        ['id' => '7-3', 'text' => '[AGENT:DECISION] Use GD', 'created' => 1791010200000],
    ])]);

    $comments = repository()->comments('TP-2');
    $agentComments = repository()->agentComments('TP-2');

    expect($comments)->toHaveCount(3)
        ->and($comments[0]->issueId)->toBe('TP-2')
        ->and($comments[0]->authorName)->toBe('Alexander Osipov')
        ->and($agentComments)->toHaveCount(2)
        ->and($agentComments->claimOwner())->toBe('host:/srv/worktrees/TP-2');

    Http::assertSent(fn (Request $request): bool => $request['fields'] === Client::COMMENT_FIELDS);
});

it('reads the latest comments of the project from the activity stream', function () {
    Http::fake(['yt.example.com/api/activities*' => Http::response([
        [
            'id' => 'a-2',
            'author' => ['login' => 'a.osipov', 'fullName' => 'Alexander Osipov'],
            'added' => [['id' => '7-63', 'text' => "[AGENT:DONE]\nEpic is ready", 'created' => 1791013037440, 'issue' => ['idReadable' => 'TP-2', 'summary' => '[EPIC] Avatar']]],
        ],
        [
            'id' => 'a-1',
            'author' => ['login' => 'someone'],
            'added' => [['id' => '7-60', 'text' => 'Looks good', 'created' => 1791013000000, 'issue' => ['idReadable' => 'TP-3']], 'junk'],
        ],
        ['id' => 'a-0', 'added' => 'junk'],
    ])]);

    $comments = repository()->recentComments(20);
    $agentComments = repository()->recentAgentComments(20);

    expect($comments)->toHaveCount(2)
        ->and($comments[0]->toArray())->toBe([
            'id' => '7-63',
            'issueId' => 'TP-2',
            'issueSummary' => '[EPIC] Avatar',
            'text' => "[AGENT:DONE]\nEpic is ready",
            'author' => 'a.osipov',
            'authorName' => 'Alexander Osipov',
            'createdAt' => '2026-10-03T07:37:17+00:00',
        ])
        ->and($comments[1]->issueId)->toBe('TP-3')
        ->and($agentComments)->toHaveCount(1)
        ->and($agentComments[0]->kind)->toBe('DONE')
        ->and($agentComments[0]->issueId)->toBe('TP-2');

    Http::assertSent(fn (Request $request): bool => $request['issueQuery'] === 'project: TP');
});

it('links to issues in YouTrack', function () {
    expect(repository()->url('TP-2'))->toBe('https://yt.example.com/issue/TP-2');
});
