<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Obrazmisli\Agentio\Telegram\Conversation;
use Obrazmisli\Agentio\Telegram\Jobs\NotifyDeveloper;
use Obrazmisli\Agentio\Telegram\Jobs\SendTelegramMessage;
use Obrazmisli\Agentio\Telegram\Messenger;
use Obrazmisli\Agentio\Telegram\Reports;
use Obrazmisli\Agentio\Tests\Fakes\FakeYouTrackMcp;

it('sends the message and remembers what it is about', function () {
    projectWithBot();
    fakeTelegram(['sendMessage' => ['ok' => true, 'result' => ['message_id' => 501]]]);

    app()->call([new SendTelegramMessage('❓ **Вопросы по XY-1**', 'question', 'XY-1', 'Backlog'), 'handle']);

    expect(app(Conversation::class)->about(501))->toBe(['kind' => 'question', 'issue' => 'XY-1', 'stage' => 'Backlog'])
        ->and(app(Conversation::class)->history()[0]['text'])->toBe('❓ **Вопросы по XY-1**');
});

it('waits as long as Telegram asks on flood control and drops what Telegram rejects', function () {
    projectWithBot();
    fakeTelegram(['sendMessage' => Http::sequence()
        ->push(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 12]], 429)
        ->push(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
    $job = (new SendTelegramMessage('x'))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertReleased(13);

    $job = (new SendTelegramMessage('x'))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertFailed();
});

it('queues nothing without a paired bot and never throws', function () {
    projectWithBot(chatId: null);
    Queue::fake();

    expect(app(Messenger::class)->send('Привет'))->toBeFalse();
    Queue::assertNothingPushed();

    config(['agentio.telegram.chat_id' => '42']);
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

    expect(app(Messenger::class)->send('Привет'))->toBeFalse();
});

it('reports the events of the loop briefly', function () {
    projectWithBot();
    Queue::fake();
    $mcp = (new FakeYouTrackMcp)
        ->issue('XY-1', 'Idea', 'Done', tags: ['idea'], summary: '[IDEA] Оплата', relatesTo: ['XY-2'])
        ->issue('XY-2', 'Epic', 'Review', summary: '[EPIC] Оплата заказов', relatesTo: ['XY-1'])
        ->comment('XY-2', "[AGENT:DONE]\n**Сделано:**\n- Оплата картой\n- Возвраты\n**Коммиты:** `abc`")
        ->fake();

    $reports = app(Reports::class);

    expect($reports->event('planned', 'XY-1'))->toBe("🗂 Идея XY-1 «Оплата» спланирована.\n\nЭпики:\n• XY-2 Оплата заказов — Review")
        ->and($reports->event('review', 'XY-2'))->toContain('✅ Эпик XY-2 «Оплата заказов» готов к приёмке: ветка XY-2.', "Сделано:\n- Оплата картой\n- Возвраты", 'php artisan agentio:accept XY-2')
        ->and($reports->event('merged', 'XY-2'))->toBe('🚀 Эпик XY-2 «Оплата заказов» принят автоматически: слит в dev и закрыт.')
        ->and($reports->event('blocked', 'XY-1', 'plan-XY-1'))->toContain('сессия планирования', 'php artisan agentio:log plan-XY-1')
        ->and($reports->event('review', 'XY-404'))->toStartWith('✅ Эпик XY-404 готов к приёмке');

    app()->call([new NotifyDeveloper('merged', 'XY-2'), 'handle']);

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->kind === 'report' && $job->issue === 'XY-2' && str_starts_with($job->text, '🚀'));
});
