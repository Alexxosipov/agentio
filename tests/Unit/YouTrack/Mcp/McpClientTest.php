<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\Tests\Fakes\FakeYouTrackMcp;
use Obrazmisli\Agentio\YouTrack\Mcp\McpClient;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
});

function mcpClient(string $token = 'secret-token'): McpClient
{
    return new McpClient('https://yt.example.com/', $token, 30, 1);
}

it('initializes the session once and calls tools with the bearer token and the session id', function () {
    (new FakeYouTrackMcp)->fake();
    $client = mcpClient();

    expect($client->call('get_current_user'))->toMatchArray(['login' => 'agent'])
        ->and($client->call('get_project', ['projectKey' => 'XY']))->toMatchArray(['key' => 'XY']);

    $methods = array_map(fn (array $pair): ?string => FakeYouTrackMcp::payload($pair[0])['method'] ?? null, Http::recorded()->all());

    expect($methods)->toBe(['initialize', 'notifications/initialized', 'tools/call', 'tools/call']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://yt.example.com/mcp'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request->hasHeader('Accept', 'application/json, text/event-stream'));
    Http::assertSent(fn (Request $request): bool => (FakeYouTrackMcp::payload($request)['method'] ?? null) === 'tools/call'
        && $request->hasHeader('Mcp-Session-Id', 'session-1')
        && FakeYouTrackMcp::payload($request)['params'] === ['name' => 'get_project', 'arguments' => ['projectKey' => 'XY']]);
});

it('returns plain text answers as text', function () {
    (new FakeYouTrackMcp)->issue('XY-1', 'Task', 'Ready')->fake();

    expect(mcpClient()->callText('add_issue_comment', ['issueId' => 'XY-1', 'text' => 'Hi']))->toBe('Comment added to XY-1')
        ->and(mcpClient()->call('add_issue_comment', ['issueId' => 'XY-1', 'text' => 'Hi']))->toBe(['text' => 'Comment added to XY-1']);
});

it('turns tool errors into exceptions, not found ones with status 404', function () {
    (new FakeYouTrackMcp)->fake();

    expect(fn () => mcpClient()->call('get_issue', ['issueId' => 'XY-404']))
        ->toThrow(fn (YouTrackException $exception) => expect($exception->getMessage())->toBe('YouTrack MCP get_issue failed: Issue not found: XY-404')
            ->and($exception->isNotFound())->toBeTrue());

    expect(fn () => mcpClient()->call('nope'))
        ->toThrow(fn (YouTrackException $exception) => expect($exception->isNotFound())->toBeFalse());
});

it('reports a rejected token without the token', function () {
    (new FakeYouTrackMcp)->fake();

    expect(fn () => mcpClient('wrong-token')->call('get_current_user'))
        ->toThrow(fn (YouTrackException $exception) => expect($exception->getMessage())->toContain('HTTP 401', 'token was rejected')
            ->not->toContain('wrong-token')
            ->and($exception->status)->toBe(401));
});

it('fails clearly when YouTrack is not configured', function (?string $url, ?string $token) {
    $client = new McpClient($url, $token);

    expect($client->isConfigured())->toBeFalse()
        ->and(fn () => $client->call('get_current_user'))->toThrow(YouTrackException::class, 'YouTrack is not configured');

    Http::assertNothingSent();
})->with([
    'no url' => [null, 'token'],
    'no token' => ['https://yt.example.com', null],
]);

it('reads answers sent as server-sent events', function () {
    Http::fake(['yt.example.com/mcp' => function (Request $request) {
        $id = FakeYouTrackMcp::payload($request)['id'] ?? null;

        if ($id === null) {
            return Http::response('', 202);
        }

        $result = (FakeYouTrackMcp::payload($request)['method'] ?? '') === 'initialize' ? ['protocolVersion' => '2025-06-18'] : ['content' => [['type' => 'text', 'text' => '{"login":"sse"}']], 'isError' => false];

        return Http::response("event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\n\nevent: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result])."\n\n", 200, ['Content-Type' => 'text/event-stream']);
    }]);

    expect(mcpClient()->call('get_current_user'))->toBe(['login' => 'sse']);
});

it('explains a URL that is not a YouTrack MCP endpoint', function () {
    Http::fake(['yt.example.com/mcp' => Http::response('<html>Not here</html>', 200, ['Content-Type' => 'text/html'])]);

    expect(fn () => mcpClient()->call('get_current_user'))->toThrow(YouTrackException::class, 'is https://yt.example.com/mcp the MCP endpoint of a YouTrack instance?');
});

it('reports JSON-RPC errors and HTTP failures', function () {
    Http::fake(['yt.example.com/mcp' => Http::sequence()
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32601, 'message' => 'Method not found']])
        ->push('Bad gateway', 502)
        ->push('Bad gateway', 502)]);

    expect(fn () => mcpClient()->call('get_current_user'))->toThrow(YouTrackException::class, 'YouTrack MCP initialize failed: Method not found')
        ->and(fn () => mcpClient()->call('get_current_user'))->toThrow(YouTrackException::class, 'failed with HTTP 502');
});

it('reads every page of a paginated tool', function () {
    $server = (new FakeYouTrackMcp)->fake();

    foreach (range(1, 45) as $number) {
        $server->issue('XY-'.$number, 'Task', 'Ready');
    }

    $issues = mcpClient()->paginate('search_issues', ['query' => 'project: XY'], 20, 'issuesPage');

    expect(array_column($issues, 'id'))->toHaveCount(45)
        ->and(array_column($server->callsOf('search_issues'), 'offset'))->toBe([0, 20, 40]);

    foreach (range(1, 12) as $number) {
        $server->comment('XY-1', 'Comment '.$number);
    }

    expect(mcpClient()->paginate('get_issue_comments', ['issueId' => 'XY-1'], 10))->toHaveCount(12)
        ->and(array_column($server->callsOf('get_issue_comments'), 'offset'))->toBe([0, 10]);
});
