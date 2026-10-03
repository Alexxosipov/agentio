<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\Dashboard\YouTrackSource;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\IssueRepository;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    config(['cache.default' => 'array']);
});

function source(int $ttl = 5, ?string $url = 'https://yt.example.com'): YouTrackSource
{
    return new YouTrackSource(new IssueRepository(new Client($url, 'secret', 30, 0), 'TP'), $ttl);
}

it('does not call YouTrack when it is not configured', function () {
    $source = source(url: null);

    expect($source->attempt(fn (): string => 'called', 'fallback'))->toBe('fallback')
        ->and($source->health())->toBe(['configured' => false, 'ok' => false, 'error' => null])
        ->and($source->url('TP-1'))->toBeNull()
        ->and($source->projectUrl())->toBeNull();

    Http::assertNothingSent();
});

it('remembers the first failure and returns the fallback', function () {
    $source = source();

    expect($source->attempt(fn (): never => throw new YouTrackException('first'), []))->toBe([])
        ->and($source->attempt(fn (): never => throw new YouTrackException('second'), 1))->toBe(1)
        ->and($source->health())->toBe(['configured' => true, 'ok' => false, 'error' => 'first']);
});

it('caches answers and failures for the configured time', function () {
    Http::fake(['yt.example.com/api/issues?*' => Http::response(['error_description' => 'Server down'], 503)]);

    expect(fn () => source()->issues())->toThrow(YouTrackException::class, 'Server down');
    expect(fn () => source()->issues())->toThrow(YouTrackException::class, 'Server down');

    Http::assertSentCount(1);
});

it('reads YouTrack every time when the cache is disabled', function () {
    Http::fake(['yt.example.com/api/issues?*' => Http::response([apiIssue('TP-1', ['Type' => 'Epic', 'State' => 'Ready'])])]);

    expect(source(0)->issues()[0]->id)->toBe('TP-1')
        ->and(source(0)->issues())->toHaveCount(1);

    Http::assertSentCount(2);
});

it('takes the cache time from the config by default', function () {
    config(['agentio.ui.cache' => 0]);
    Http::fake(['yt.example.com/api/issues?*' => Http::response([])]);

    $source = new YouTrackSource(new IssueRepository(new Client('https://yt.example.com', 'secret', 30, 0), 'TP'));
    $source->issues();
    $source->issues();

    Http::assertSentCount(2);
});

it('loads linked issues of other projects and ignores missing ones', function () {
    Http::fake([
        'yt.example.com/api/issues?*' => Http::response([apiIssue('TP-1', ['Type' => 'Task', 'State' => 'Ready'], ['depends on' => ['EXT-1', 'GONE-1']])]),
        'yt.example.com/api/issues/EXT-1?*' => Http::response(apiIssue('EXT-1', ['Type' => 'Task', 'State' => 'Done'])),
        'yt.example.com/api/issues/GONE-1?*' => Http::response(['error_description' => 'Not found'], 404),
        'yt.example.com/api/issues/BROKEN-1?*' => Http::response(['error_description' => 'Boom'], 500),
    ]);

    $graph = source()->graph();

    expect($graph->unmetDependencies('TP-1'))->toBe(['GONE-1'])
        ->and($graph->find('EXT-1')?->state())->toBe('Done')
        ->and(fn () => $graph->find('BROKEN-1'))->toThrow(YouTrackException::class, 'Boom');
});

it('strips the type prefix agents put in summaries', function () {
    expect(YouTrackSource::summary('[EPIC] Аватар'))->toBe('Аватар')
        ->and(YouTrackSource::summary('[task]  Тест'))->toBe('Тест')
        ->and(YouTrackSource::summary('Без префикса [TASK]'))->toBe('Без префикса [TASK]');
});
