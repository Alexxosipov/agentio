<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Telegram\Conversation;
use Obrazmisli\Agentio\Telegram\IncomingMessage;
use Obrazmisli\Agentio\Telegram\Jobs\HandleTelegramUpdate;
use Obrazmisli\Agentio\Telegram\Jobs\SendTelegramMessage;
use Obrazmisli\Agentio\Telegram\MessageHandler;

beforeEach(function () {
    Queue::fake();
    Process::fake();
    fakeTelegram(['sendChatAction' => ['ok' => true, 'result' => true]]);
});

function receive(array $message = [], string $chatId = '42'): void
{
    app(MessageHandler::class)->receive((IncomingMessage::fromUpdate(telegramUpdate(7, $message, $chatId))));
}

it('pairs the chat that sends the code of agentio:setup-telegram', function () {
    $project = projectWithBot(chatId: null);
    $code = app(Conversation::class)->startPairing();

    receive(['text' => '/start '.$code], '555');

    expect((new EnvFile($project.'/.env'))->get('AGENTIO_TELEGRAM_CHAT_ID'))->toBe('555')
        ->and(app(Conversation::class)->pairingCode())->toBeNull();
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->chatId === '555' && str_contains($job->text, 'Бот привязан к этому чату'));
});

it('does not pair with a wrong code', function () {
    $project = projectWithBot(chatId: null);
    app(Conversation::class)->startPairing();

    receive(['text' => '/start nope'], '666');
    receive(['text' => 'привет'], '666');

    expect((new EnvFile($project.'/.env'))->get('AGENTIO_TELEGRAM_CHAT_ID'))->toBeNull();
    Queue::assertPushed(SendTelegramMessage::class, 1);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'ещё не привязан'));
});

it('ignores other chats', function () {
    projectWithBot();

    receive(['text' => 'Удали всё'], '999');

    Queue::assertNothingPushed();
    Process::assertNothingRan();
    expect(app(Conversation::class)->inboxKeys())->toBe([]);
});

it('answers /help itself', function () {
    projectWithBot();

    receive(['text' => '/help']);

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->kind === 'help' && $job->replyTo === 1007 && str_contains($job->text, 'менеджер проекта'));
    Process::assertNothingRan();
});

it('hands a message to the assistant in a detached process', function () {
    $project = projectWithBot();

    receive(['text' => 'Что сейчас в работе?']);

    expect(app(Conversation::class)->inboxKeys())->toBe(['7']);
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && $process->command[0] === 'bash'
        && array_slice($process->command, -4) === [$project.'/artisan', 'agentio:telegram', 'assist', '7']
        && $process->environment['AGENTIO_TELEGRAM_BOT_TOKEN'] === false
        && str_ends_with((string) $process->environment['AGENTIO_ASSISTANT_LOG'], 'telegram-assistant.log'));
    Queue::assertNothingPushed();
});

it('handles an update once even when it is queued twice', function () {
    projectWithBot();

    $job = new HandleTelegramUpdate(telegramUpdate(7, ['text' => '/help']));

    expect($job->connection)->toBe('redis')->and($job->queue)->toBe('default')->and($job->timeout)->toBeLessThan(60);

    app()->call([$job, 'handle']);
    app()->call([new HandleTelegramUpdate(telegramUpdate(7, ['text' => '/help'])), 'handle']);

    Queue::assertPushed(SendTelegramMessage::class, 1);
});
