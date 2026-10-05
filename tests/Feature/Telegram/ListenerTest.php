<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\Telegram\Api\Requests\GetUpdates;
use Obrazmisli\Agentio\Telegram\Conversation;
use Obrazmisli\Agentio\Telegram\Jobs\HandleTelegramUpdate;
use Obrazmisli\Agentio\Telegram\Jobs\SendTelegramMessage;
use Obrazmisli\Agentio\Telegram\Listener;
use Obrazmisli\Agentio\Telegram\QuestionWatcher;
use Obrazmisli\Agentio\YouTrack\Comment;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The comment activities of the YouTrack REST API, newest first.
 *
 * @param  list<array{0: string, 1: string, 2: int}>  $comments  Issue, text, created (ms)
 */
function fakeCommentActivities(array $comments): void
{
    static $current = [];
    static $faked = null;
    $current = $comments;

    // One fake per test, answering with the comments of the last call.
    if ($faked !== test()) {
        $faked = test();
        Http::fake(['yt.example.com/api/activities*' => function () use (&$current) {
            return Http::response(array_map(fn (array $comment): array => [
                'id' => 'a-'.$comment[2],
                'timestamp' => $comment[2],
                'author' => ['login' => 'agent', 'fullName' => 'Agent'],
                'added' => [['id' => 'c-'.$comment[2], 'text' => $comment[1], 'created' => $comment[2], 'issue' => ['idReadable' => $comment[0], 'summary' => '[IDEA] Оплата']]],
            ], $current));
        }]);
    }
}

it('queues every update and confirms it only after', function () {
    projectWithBot();
    Queue::fake();
    Sleep::fake();
    fakeCommentActivities([]);
    $mock = MockClient::global([GetUpdates::class => MockResponse::make(['ok' => true, 'result' => [telegramUpdate(10), telegramUpdate(11)]])]);
    $log = [];

    app(Listener::class)->run(fn (): bool => false, function (string $line) use (&$log): void {
        $log[] = $line;
    }, passes: 1, pollTimeout: 0);

    Queue::assertPushed(HandleTelegramUpdate::class, 2);
    Queue::assertPushed(HandleTelegramUpdate::class, fn (HandleTelegramUpdate $job): bool => $job->update['update_id'] === 11);
    expect(app(Conversation::class)->offset())->toBe(12)->and($log)->toBe([]);
    $mock->assertSent(fn ($request): bool => $request instanceof GetUpdates && $request->body()->get('offset') === null);
});

it('waits after a conflict with another listener', function () {
    projectWithBot();
    Queue::fake();
    Sleep::fake();
    fakeCommentActivities([]);
    MockClient::global([GetUpdates::class => MockResponse::make(['ok' => false, 'error_code' => 409, 'description' => 'Conflict: terminated by other getUpdates request'], 409)]);
    $log = [];

    app(Listener::class)->run(fn (): bool => false, function (string $line) use (&$log): void {
        $log[] = $line;
    }, passes: 1, pollTimeout: 0);

    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(15)->seconds()]);
    expect($log)->toBe(['getUpdates: Telegram: Conflict: terminated by other getUpdates request'])->and(app(Conversation::class)->offset())->toBeNull();
});

it('sends the questions the agents asked since the last look', function () {
    projectWithBot();
    Queue::fake();
    $conversation = app(Conversation::class);
    $watcher = app(QuestionWatcher::class);
    $sent = [];
    $send = function (Comment $comment, string $text) use (&$sent): void {
        $sent[] = [$comment->issueId, $text];
    };

    fakeCommentActivities([['XY-1', "[AGENT:BLOCKED]\nстарый вопрос", 1_791_000_000_000]]);

    expect($watcher->poll($send))->toBe(0)->and($conversation->watermark())->toBeGreaterThanOrEqual(1_791_000_000_000);

    $conversation->setWatermark(1_791_000_000_000);
    fakeCommentActivities([
        ['XY-3', "[AGENT:BLOCKED]\n**Что остановлено:** эпик.\n\n**В1. Как?**\n\n**Как ответить:** комментарием в этой задаче, затем Stage → Ready.", 1_791_000_003_000],
        ['XY-1', '[AGENT:DONE] готово', 1_791_000_002_000],
        ['XY-2', "[AGENT:BLOCKED]\nвопрос XY-2", 1_791_000_001_000],
        ['XY-1', "[AGENT:BLOCKED]\nстарый вопрос", 1_791_000_000_000],
    ]);

    expect($watcher->poll($send))->toBe(2)
        ->and(array_column($sent, 0))->toBe(['XY-2', 'XY-3'])
        ->and($sent[1][1])->toStartWith('❓ **Вопросы по XY-3** «[IDEA] Оплата»')
        ->and($sent[1][1])->toContain('**В1. Как?**', 'Ответьте на это сообщение (reply)')
        ->and($sent[1][1])->not->toContain('Как ответить:')
        ->and($conversation->watermark())->toBe(1_791_000_003_000);

    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'issueQuery=project: XY'));
});

it('queues the questions it finds as messages tied to their issue', function () {
    projectWithBot();
    Queue::fake();
    app(Conversation::class)->setWatermark(1_791_000_000_000);
    fakeCommentActivities([['XY-1', "[AGENT:BLOCKED]\n**Нужен ответ:** комментарием, затем Stage → Backlog.", 1_791_000_001_000]]);
    MockClient::global([GetUpdates::class => MockResponse::make(['ok' => true, 'result' => []])]);

    app(Listener::class)->run(fn (): bool => false, fn (string $line) => null, passes: 1, pollTimeout: 0);

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->kind === 'question' && $job->issue === 'XY-1' && $job->stage === 'Backlog');
});
