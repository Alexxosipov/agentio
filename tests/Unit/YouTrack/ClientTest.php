<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
});

function client(int $retries = 2): Client
{
    return new Client('https://yt.example.com/', 'secret-token', 30, $retries);
}

it('sends authenticated JSON requests under /api', function () {
    Http::fake(['yt.example.com/api/issues/TP-1*' => Http::response(['idReadable' => 'TP-1'])]);

    expect(client()->get('/issues/TP-1', ['fields' => 'idReadable']))->toBe(['idReadable' => 'TP-1']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://yt.example.com/api/issues/TP-1?fields=idReadable'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request->hasHeader('Accept', 'application/json'));
});

it('posts JSON bodies and sends deletes', function () {
    Http::fake(['*' => Http::response(['id' => '1'])]);

    client()->post('issues/TP-1/comments', ['text' => 'Hello'], ['fields' => 'id']);
    client()->delete('issues/TP-1/tags/5-1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://yt.example.com/api/issues/TP-1/comments?fields=id'
        && $request->data() === ['text' => 'Hello']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://yt.example.com/api/issues/TP-1/tags/5-1');
});

it('returns an empty array for empty or scalar bodies', function () {
    Http::fake(['*' => Http::response('', 200)]);

    expect(client()->delete('issues/TP-1/tags/5-1'))->toBe([]);
});

it('fails clearly when YouTrack is not configured', function (?string $url, ?string $token) {
    $client = new Client($url, $token);

    expect($client->isConfigured())->toBeFalse()
        ->and(fn () => $client->get('issues'))->toThrow(YouTrackException::class, 'YouTrack is not configured');

    Http::assertNothingSent();
})->with([
    'no url' => [null, 'token'],
    'no token' => ['https://yt.example.com', null],
    'empty token' => ['https://yt.example.com', ''],
]);

it('reports the YouTrack error description on failure', function () {
    Http::fake(['*' => Http::response(['error' => 'Not Found', 'error_description' => 'Entity with id TP-404 not found'], 404)]);

    try {
        client()->get('issues/TP-404');
        $this->fail('No exception was thrown.');
    } catch (YouTrackException $exception) {
        expect($exception->getMessage())->toBe('YouTrack GET issues/TP-404 failed with HTTP 404: Entity with id TP-404 not found')
            ->and($exception->status)->toBe(404)
            ->and($exception->isNotFound())->toBeTrue()
            ->and($exception->getMessage())->not->toContain('secret-token');
    }
});

it('reports the raw body or reason when there is no error description', function (mixed $body, string $reason) {
    Http::fake(['*' => Http::response($body, 403)]);

    expect(fn () => client()->get('issues'))->toThrow(YouTrackException::class, 'failed with HTTP 403: '.$reason);
})->with([
    'text body' => ['Forbidden by proxy', 'Forbidden by proxy'],
    'empty body' => ['', 'Forbidden'],
]);

it('retries server errors and rate limits', function () {
    Http::fake(['*' => Http::sequence()
        ->push('busy', 503)
        ->push('slow down', 429)
        ->push(['ok' => true])]);

    expect(client()->get('issues'))->toBe(['ok' => true]);

    Http::assertSentCount(3);
});

it('gives up after the configured retries', function () {
    Http::fake(['*' => Http::response('busy', 500)]);

    expect(fn () => client(retries: 1)->get('issues'))->toThrow(YouTrackException::class, 'HTTP 500');

    Http::assertSentCount(2);
});

it('does not retry client errors', function () {
    Http::fake(['*' => Http::response(['error_description' => 'Bad query'], 400)]);

    expect(fn () => client()->get('issues'))->toThrow(YouTrackException::class, 'Bad query');

    Http::assertSentCount(1);
});

it('wraps connection failures', function () {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => client(retries: 0)->get('issues'))->toThrow(YouTrackException::class, 'YouTrack GET issues failed:');
});

it('fetches every page of a collection', function () {
    Http::fake(['*' => Http::sequence()
        ->push([['id' => 1], 'not-an-object', ['id' => 2]])
        ->push([['id' => 3]])]);

    expect(client()->paginate('tags', ['fields' => 'id'], 3))->toBe([['id' => 1], ['id' => 2], ['id' => 3]]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '%24top=3&%24skip=0'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '%24top=3&%24skip=3'));
});

it('builds links to the web UI', function () {
    expect(client()->issueUrl('TP-1'))->toBe('https://yt.example.com/issue/TP-1')
        ->and(client()->articleUrl('TP-A-18'))->toBe('https://yt.example.com/articles/TP-A-18');
});

it('reads issues, comments and comment activities', function () {
    Http::fake([
        'yt.example.com/api/issues/TP-1/comments*' => Http::response([['id' => '7-1', 'text' => 'Hi']]),
        'yt.example.com/api/issues/TP-1?*' => Http::response(['idReadable' => 'TP-1']),
        'yt.example.com/api/issues?*' => Http::response([['idReadable' => 'TP-1']]),
        'yt.example.com/api/activities*' => Http::response([['id' => 'a-1'], 'junk']),
    ]);

    expect(client()->searchIssues('project: TP'))->toBe([['idReadable' => 'TP-1']])
        ->and(client()->issue('TP-1'))->toBe(['idReadable' => 'TP-1'])
        ->and(client()->comments('TP-1'))->toBe([['id' => '7-1', 'text' => 'Hi']])
        ->and(client()->commentActivities('project: TP', 10))->toBe([['id' => 'a-1']]);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://yt.example.com/api/issues?')
        && $request['query'] === 'project: TP'
        && $request['fields'] === Client::ISSUE_FIELDS);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://yt.example.com/api/issues/TP-1?')
        && $request['fields'] === Client::ISSUE_FIELDS.',description');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://yt.example.com/api/activities?')
        && $request['categories'] === 'CommentsCategory'
        && $request['issueQuery'] === 'project: TP'
        && $request['reverse'] === 'true'
        && str_contains($request->url(), '%24top=10'));
});

it('finds a project by its short name', function () {
    Http::fake(['yt.example.com/api/admin/projects*' => Http::response([
        ['id' => '0-1', 'shortName' => 'TPX', 'name' => 'Other'],
        ['id' => '0-3', 'shortName' => 'TP', 'name' => 'Test project'],
    ])]);

    expect(client()->project('TP'))->toBe(['id' => '0-3', 'shortName' => 'TP', 'name' => 'Test project'])
        ->and(client()->projectId('TP'))->toBe('0-3')
        ->and(client()->project('NOPE'))->toBeNull()
        ->and(fn () => client()->projectId('NOPE'))->toThrow(YouTrackException::class, 'YouTrack project "NOPE" was not found');
});

it('reads project settings, bundles, tags, saved searches and articles', function (Closure $call, string $path, array $query) {
    Http::fake(['*' => Http::response([['id' => 'x']])]);

    expect($call(client()))->toBe([['id' => 'x']]);

    Http::assertSent(function (Request $request) use ($path, $query): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $sent);

        return $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/api/'.$path
            && array_intersect_assoc($query, $sent) === $query;
    });
})->with([
    'project custom fields' => [fn (Client $client): array => $client->projectCustomFields('0-3'), 'admin/projects/0-3/customFields', []],
    'custom fields' => [fn (Client $client): array => $client->customFields(), 'admin/customFieldSettings/customFields', ['fields' => 'id,name,fieldType(id),fieldDefaults(bundle(id)),instances(project(id),bundle(id))']],
    'state bundles' => [fn (Client $client): array => $client->bundles('state'), 'admin/customFieldSettings/bundles/state', []],
    'tags' => [fn (Client $client): array => $client->tags('agent'), 'tags', ['query' => 'agent', 'fields' => 'id,name']],
    'all tags' => [fn (Client $client): array => $client->tags(), 'tags', ['fields' => 'id,name']],
    'saved queries' => [fn (Client $client): array => $client->savedQueries(), 'savedQueries', ['fields' => 'id,name,query']],
    'articles' => [fn (Client $client): array => $client->articles('project: TP'), 'articles', ['query' => 'project: TP']],
    'all articles' => [fn (Client $client): array => $client->articles(), 'articles', ['fields' => Client::ARTICLE_FIELDS]],
]);

it('tells whether a project has issues', function (array $issues, bool $expected) {
    Http::fake(['*' => Http::response($issues)]);

    expect(client()->projectHasIssues('XY'))->toBe($expected);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $sent);

        return parse_url($request->url(), PHP_URL_PATH) === '/api/issues' && $sent['query'] === 'project: XY' && $sent['$top'] === '1';
    });
})->with([
    'empty' => [[], false],
    'with issues' => [[['id' => '2-1']], true],
]);

it('reads a single article', function () {
    Http::fake(['*' => Http::response(['idReadable' => 'TP-A-1'])]);

    expect(client()->article('TP-A-1'))->toBe(['idReadable' => 'TP-A-1']);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://yt.example.com/api/articles/TP-A-1?'));
});

it('creates YouTrack settings for the installer', function (Closure $call, string $path, array $body) {
    Http::fake(['*' => Http::response(['id' => 'new'])]);

    expect($call(client()))->toBe(['id' => 'new']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && parse_url($request->url(), PHP_URL_PATH) === '/api/'.$path
        && $request->data() === $body);
})->with([
    'article' => [
        fn (Client $client): array => $client->createArticle('0-3', 'Architecture', '# Architecture'),
        'articles',
        ['project' => ['id' => '0-3'], 'summary' => 'Architecture', 'content' => '# Architecture'],
    ],
    'child article' => [
        fn (Client $client): array => $client->createArticle('0-3', 'ADR-001', 'Text', '155-1'),
        'articles',
        ['project' => ['id' => '0-3'], 'summary' => 'ADR-001', 'content' => 'Text', 'parentArticle' => ['id' => '155-1']],
    ],
    'article update' => [
        fn (Client $client): array => $client->updateArticle('TP-A-1', content: 'New text'),
        'articles/TP-A-1',
        ['content' => 'New text'],
    ],
    'custom field' => [
        fn (Client $client): array => $client->createCustomField('Stage', 'state[1]'),
        'admin/customFieldSettings/customFields',
        ['name' => 'Stage', 'fieldType' => ['id' => 'state[1]'], 'isAutoAttached' => false],
    ],
    'project field with bundle' => [
        fn (Client $client): array => $client->attachCustomField('0-3', '161-12', 'StateProjectCustomField', '165-6', 'StateBundle', false),
        'admin/projects/0-3/customFields',
        ['$type' => 'StateProjectCustomField', 'field' => ['id' => '161-12'], 'canBeEmpty' => false, 'bundle' => ['id' => '165-6', '$type' => 'StateBundle']],
    ],
    'project field without bundle' => [
        fn (Client $client): array => $client->attachCustomField('0-3', '161-3', 'UserProjectCustomField'),
        'admin/projects/0-3/customFields',
        ['$type' => 'UserProjectCustomField', 'field' => ['id' => '161-3'], 'canBeEmpty' => true],
    ],
    'bundle of a project field' => [
        fn (Client $client): array => $client->setProjectFieldBundle('0-3', '189-18', 'StateProjectCustomField', '165-8', 'StateBundle'),
        'admin/projects/0-3/customFields/189-18',
        ['$type' => 'StateProjectCustomField', 'bundle' => ['id' => '165-8', '$type' => 'StateBundle']],
    ],
    'bundle' => [
        fn (Client $client): array => $client->createBundle('state', 'TP States', [['name' => 'Done', 'isResolved' => true]]),
        'admin/customFieldSettings/bundles/state',
        ['name' => 'TP States', 'values' => [['name' => 'Done', 'isResolved' => true]]],
    ],
    'bundle value' => [
        fn (Client $client): array => $client->addBundleValue('state', '165-5', ['name' => 'Review', 'isResolved' => false]),
        'admin/customFieldSettings/bundles/state/165-5/values',
        ['name' => 'Review', 'isResolved' => false],
    ],
    'tag' => [fn (Client $client): array => $client->createTag('agent-claimed'), 'tags', ['name' => 'agent-claimed']],
    'saved query' => [
        fn (Client $client): array => $client->createSavedQuery('TP: ready epics', 'project: TP Type: Epic State: Ready'),
        'savedQueries',
        ['name' => 'TP: ready epics', 'query' => 'project: TP Type: Epic State: Ready'],
    ],
]);
