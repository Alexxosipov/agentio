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

function receive(array $message = [], string $chatId = '42', int $update = 7): void
{
    app(MessageHandler::class)->receive((IncomingMessage::fromUpdate(telegramUpdate($update, $message, $chatId))));
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

it('says how to allow godmode while the settings keep it off', function () {
    projectWithBot();

    receive(['text' => '/godmode composer require spatie/laravel-data']);
    receive(['text' => 'Удали всё'], update: 8);

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->kind === 'help' && $job->replyTo === 1007 && str_contains($job->text, 'AGENTIO_TELEGRAM_GODMODE=true'));
    expect(app(Conversation::class)->inGodmode(3600))->toBeFalse()
        ->and(app(Conversation::class)->inbox('7'))->toBeNull()
        ->and(app(Conversation::class)->inbox('8')?->godmode)->toBeFalse();
});

it('switches godmode on and off and hands its messages over as tasks', function () {
    projectWithBot();
    config(['agentio.telegram.godmode.enabled' => true, 'agentio.telegram.godmode.idle' => 1800]);
    $conversation = app(Conversation::class);

    receive(['text' => '/godmode']);

    expect($conversation->inGodmode(1800))->toBeTrue()->and($conversation->inboxKeys())->toBe([]);
    Process::assertNothingRan();
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'Godmode включён') && str_contains($job->text, 'через 30 мин'));

    receive(['text' => 'Поставь spatie/laravel-data'], update: 8);

    expect($conversation->inbox('8')?->godmode)->toBeTrue()
        ->and($conversation->inbox('8')?->text)->toBe('Поставь spatie/laravel-data');
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && array_slice($process->command, -3) === ['agentio:telegram', 'assist', '8']);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->replyTo === 1008 && str_contains($job->text, 'Принял задачу'));

    receive(['text' => '/exit'], update: 9);
    receive(['text' => 'Что в работе?'], update: 10);

    expect($conversation->inGodmode(1800))->toBeFalse()
        ->and($conversation->inbox('9'))->toBeNull()
        ->and($conversation->inbox('10')?->godmode)->toBeFalse();
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->replyTo === 1009 && str_contains($job->text, 'Godmode выключен: дальше отвечает ассистент'));
});

it('runs the task given with /godmode right away', function () {
    projectWithBot();
    config(['agentio.telegram.godmode.enabled' => true]);

    receive(['text' => '/godmode Обнови README и закоммить']);

    expect(app(Conversation::class)->inbox('7')?->godmode)->toBeTrue()
        ->and(app(Conversation::class)->inbox('7')?->text)->toBe('Обнови README и закоммить')
        ->and(app(Conversation::class)->inGodmode(3600))->toBeTrue();
});

it('leaves a reply to the questions of the agents to the assistant in godmode', function () {
    projectWithBot();
    config(['agentio.telegram.godmode.enabled' => true]);
    $conversation = app(Conversation::class);
    $conversation->startGodmode();
    $conversation->remember([500], 'question', 'XY-1', 'Backlog');

    receive(['text' => 'В1: б', 'reply_to_message' => ['message_id' => 500, 'from' => ['id' => 1, 'is_bot' => true], 'text' => '❓ Вопросы по XY-1']]);

    expect($conversation->inbox('7')?->godmode)->toBeFalse()->and($conversation->inGodmode(3600))->toBeTrue();
});

it('switches godmode off when it was left without messages', function () {
    projectWithBot();
    config(['agentio.telegram.godmode.enabled' => true, 'agentio.telegram.godmode.idle' => 600]);
    $conversation = app(Conversation::class);
    $conversation->startGodmode();
    file_put_contents($conversation->directory().'/state.json', (string) json_encode(['godmode' => ['session' => 'abc-1', 'at' => time() - 601]]));

    receive(['text' => 'Удали ветку']);

    expect($conversation->inbox('7')?->godmode)->toBeFalse()
        ->and($conversation->godmodeExpired(600))->toBeFalse()
        ->and($conversation->godmodeSession())->toBeNull();
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'Godmode выключился'));
});

it('says that godmode is not on when asked to leave it', function () {
    projectWithBot();

    receive(['text' => '/godmode off']);

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->text === 'Godmode не включён.');
    Process::assertNothingRan();
});
