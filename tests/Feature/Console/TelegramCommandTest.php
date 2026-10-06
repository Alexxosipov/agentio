<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Obrazmisli\Agentio\Telegram\Conversation;
use Obrazmisli\Agentio\Telegram\Jobs\HandleTelegramUpdate;
use Obrazmisli\Agentio\Telegram\Jobs\NotifyDeveloper;
use Obrazmisli\Agentio\Telegram\Jobs\SendTelegramMessage;

it('queues a report of the loop for the paired bot', function () {
    projectWithBot();
    Queue::fake();

    $this->artisan('agentio:telegram', ['action' => 'notify', 'argument' => 'blocked', 'id' => 'XY-2', '--name' => 'XY-2'])->assertSuccessful();

    Queue::assertPushed(NotifyDeveloper::class, fn (NotifyDeveloper $job): bool => $job->event === 'blocked' && $job->issue === 'XY-2' && $job->name === 'XY-2' && $job->connection === 'redis' && $job->queue === 'default');
});

it('never fails the loop: without a bot, with an unknown event or a broken queue', function (?string $chat, string $event) {
    projectWithBot($chat);
    Queue::fake();

    $this->artisan('agentio:telegram', ['action' => 'notify', 'argument' => $event, 'id' => 'XY-2'])->assertSuccessful();

    Queue::assertNothingPushed();
})->with([
    'not paired' => [null, 'review'],
    'unknown event' => ['42', 'deleted'],
]);

it('sends a text through the queue', function () {
    projectWithBot();
    Queue::fake();

    $this->artisan('agentio:telegram', ['action' => 'send', 'argument' => 'Проверка'])->expectsOutputToContain('Queued.')->assertSuccessful();

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->text === 'Проверка');

    projectWithBot(null);
    $this->artisan('agentio:telegram', ['action' => 'send', 'argument' => 'x'])->expectsOutputToContain('not paired')->assertExitCode(2);
});

it('listens for a given number of polls', function () {
    projectWithBot();
    Queue::fake();
    Http::fake(['yt.example.com/api/activities*' => Http::response([])]);
    fakeTelegram(['getUpdates' => ['ok' => true, 'result' => [telegramUpdate(3)]]]);

    $this->artisan('agentio:telegram', ['action' => 'listen', '--passes' => 1, '--poll-timeout' => 0])
        ->expectsOutputToContain('listening for Telegram updates')
        ->assertSuccessful();

    Queue::assertPushed(HandleTelegramUpdate::class);
    expect(app(Conversation::class)->offset())->toBe(4);
});

it('refuses to listen without a token and to answer an unknown message', function () {
    hostProject();

    $this->artisan('agentio:telegram', ['action' => 'listen'])->expectsOutputToContain('AGENTIO_TELEGRAM_BOT_TOKEN is not set')->assertExitCode(2);
    $this->artisan('agentio:telegram', ['action' => 'assist', 'argument' => '12'])->expectsOutputToContain('No message 12')->assertExitCode(2);
    $this->artisan('agentio:telegram', ['action' => 'fly'])->assertExitCode(2);
});

it('shows the state of the bot', function () {
    projectWithBot();
    Process::fake();

    $this->artisan('agentio:telegram')
        ->expectsOutputToContain('42')
        ->expectsOutputToContain('redis / default')
        ->expectsOutputToContain('not installed')
        ->assertSuccessful();
});
