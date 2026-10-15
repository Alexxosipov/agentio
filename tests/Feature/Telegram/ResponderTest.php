<?php

declare(strict_types=1);

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Obrazmisli\Agentio\Telegram\Conversation;
use Obrazmisli\Agentio\Telegram\IncomingMessage;
use Obrazmisli\Agentio\Telegram\Jobs\SendTelegramMessage;
use Obrazmisli\Agentio\Telegram\Responder;
use Obrazmisli\Agentio\Telegram\Transcription\Transcriber;
use Obrazmisli\Agentio\Tests\Fakes\FakeYouTrackMcp;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

const BLOCKED_QUESTIONS = "[AGENT:BLOCKED]\n**Что остановлено:** планирование идеи XY-1.\n**Нужен ответ:** 2 вопроса (В1–В2) — комментарием в этой задаче, затем Stage → Backlog.\n\n**В1. Оплата частями?**\n- **Рекомендация: б**";

/**
 * A project with the bot, the idea XY-1 blocked on two questions and the assistant answering with $decision.
 *
 * @param  array<string, mixed>|string  $decision  The final text of the Claude Code session
 */
function respondingProject(array|string $decision, int $exitCode = 0): FakeYouTrackMcp
{
    projectWithBot();
    Queue::fake();
    Process::fake(['*--output-format*' => Process::result((string) json_encode([
        'type' => 'result',
        'is_error' => $exitCode !== 0,
        'result' => is_string($decision) ? $decision : json_encode($decision, JSON_UNESCAPED_UNICODE),
    ]), exitCode: $exitCode)]);

    return (new FakeYouTrackMcp)
        ->issue('XY-1', 'Idea', 'Blocked', tags: ['idea'], summary: '[IDEA] Оплата')
        ->comment('XY-1', BLOCKED_QUESTIONS)
        ->fake();
}

/**
 * A message of the developer waiting for the assistant, replying to the questions of XY-1.
 */
function waitingReply(string $text = 'В1: б, В2 — как предлагаешь', array $fields = []): string
{
    $conversation = app(Conversation::class);
    $conversation->remember([500], 'question', 'XY-1', 'Backlog');

    return $conversation->putInbox(new IncomingMessage(...[
        'updateId' => 7,
        'messageId' => 1007,
        'chatId' => '42',
        'text' => $text,
        'replyToId' => 500,
        'replyToText' => '❓ Вопросы по XY-1',
        'replyToBot' => true,
        ...$fields,
    ]));
}

it('records the answer to the questions and returns the issue to work', function () {
    $mcp = respondingProject([
        'reply' => 'Понял: оплата только полная.',
        'actions' => [['type' => 'answer', 'issue' => 'XY-1', 'comment' => 'В1: б; В2: Принимаю рекомендацию', 'resume' => true]],
    ]);

    expect(app(Responder::class)->answer(waitingReply()))->toBeTrue();

    $comment = end($mcp->comments['XY-1']);

    expect($comment['text'])->toStartWith("Ответ разработчика (Telegram):\n\nВ1: б; В2: Принимаю рекомендацию")
        ->and($comment['text'])->toContain('> В1: б, В2 — как предлагаешь')
        ->and($mcp->issues['XY-1']['fields']['Stage'])->toBe('Backlog');

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->replyTo === 1007
        && $job->kind === 'answer'
        && $job->issue === 'XY-1'
        && str_contains($job->text, 'Понял: оплата только полная.')
        && str_contains($job->text, '✅ Ответ записан в XY-1, задача возвращена в Backlog')
        && $job->connection === 'redis' && $job->queue === 'default');

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && str_contains($process->command[2], 'Загрузи скилл agentio-telegram-assistant')
        && str_contains($process->command[2], 'задача XY-1, после ответа вернуть Stage Backlog')
        && in_array('dontAsk', $process->command, true)
        && $process->environment['YOUTRACK_TOKEN'] === 'secret-token');

    expect(app(Conversation::class)->inboxKeys())->toBe([])
        ->and(array_column(app(Conversation::class)->history(), 'role'))->toBe(['developer']);
});

it('keeps the issue blocked when the answer is partial', function () {
    $mcp = respondingProject([
        'reply' => 'А что по В2?',
        'actions' => [['type' => 'answer', 'issue' => 'XY-1', 'comment' => 'В1: б', 'resume' => false]],
    ]);

    app(Responder::class)->answer(waitingReply('В1: б'));

    expect($mcp->issues['XY-1']['fields']['Stage'])->toBe('Blocked')
        ->and(end($mcp->comments['XY-1'])['text'])->toStartWith('Ответ разработчика (Telegram):');
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'Stage не менял'));
});

it('creates an idea the developer described', function () {
    $mcp = respondingProject([
        'reply' => 'Хорошая мысль.',
        'actions' => [['type' => 'idea', 'summary' => '[IDEA] Оплата через СБП', 'description' => 'Нужно принимать оплату через СБП.']],
    ]);
    $mcp->on('create_issue', function (array $arguments) use ($mcp): array {
        $mcp->issue('XY-30', 'Idea', 'Backlog', summary: (string) $arguments['summary']);
        $mcp->issues['XY-30']['description'] = (string) $arguments['description'];

        // The answer of YouTrack's MCP server: the readable id under "createdIssue".
        return ['createdIssue' => ['id' => 'XY-30', 'url' => FakeYouTrackMcp::URL.'/issue/XY-30'], 'updatedFields' => ['Type', 'Stage'], 'failedToUpdateFields' => []];
    });

    app(Responder::class)->answer(app(Conversation::class)->putInbox(new IncomingMessage(8, 1008, '42', 'Сделай оплату через СБП')));

    expect($mcp->callsOf('create_issue')[0])->toMatchArray(['project' => 'XY', 'summary' => '[IDEA] Оплата через СБП', 'customFields' => ['Type' => 'Idea', 'Stage' => 'Backlog']])
        ->and($mcp->issues['XY-30']['description'])->toContain('Нужно принимать оплату через СБП.', 'Идея поставлена разработчиком в Telegram')
        ->and($mcp->issues['XY-30']['tags'])->toBe(['idea']);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->issue === 'XY-30' && str_contains($job->text, '💡 Создал идею XY-30 «Оплата через СБП»'));
});

it('creates an idea for later and takes a parked idea into development', function () {
    $mcp = respondingProject([
        'reply' => 'Отложу.',
        'actions' => [
            ['type' => 'idea', 'summary' => 'Оплата частями', 'description' => 'Оплата в 3 части.', 'park' => true],
            ['type' => 'promote', 'issue' => 'XY-7'],
            ['type' => 'promote', 'issue' => 'XY-1'],
        ],
    ]);
    $mcp->issue('XY-7', 'Idea', 'On Hold', tags: ['idea', 'parked'], summary: '[IDEA] Кэшбэк');
    $mcp->on('create_issue', function (array $arguments) use ($mcp): array {
        $mcp->issue('XY-30', 'Idea', 'Backlog', summary: (string) $arguments['summary']);

        return ['createdIssue' => ['id' => 'XY-30', 'url' => FakeYouTrackMcp::URL.'/issue/XY-30']];
    });

    app(Responder::class)->answer(app(Conversation::class)->putInbox(new IncomingMessage(8, 1008, '42', 'Запиши на потом оплату частями и бери в работу кэшбэк')));

    expect($mcp->issues['XY-30']['tags'])->toBe(['idea', 'parked'])
        ->and($mcp->issues['XY-7']['tags'])->toBe(['idea'])
        ->and($mcp->issues['XY-7']['fields']['Stage'])->toBe('Backlog')
        ->and((string) end($mcp->comments['XY-7'])['text'])->toStartWith('Идея принята в разработку разработчиком (Telegram): метка parked снята.')
        ->and($mcp->issues['XY-1']['fields']['Stage'])->toBe('Blocked');
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, '🗄 Создал отложенную идею XY-30 «Оплата частями»')
        && str_contains($job->text, '🚀 Идея XY-7 взята в работу')
        && str_contains($job->text, 'XY-1 — не отложенная идея'));
});

it('transcribes a voice message before the assistant reads it', function () {
    $mcp = respondingProject(['reply' => 'Принято.', 'actions' => [['type' => 'answer', 'issue' => 'XY-1', 'comment' => 'Принимаю рекомендации', 'resume' => true]]]);
    fakeTelegram([
        'getFile' => ['ok' => true, 'result' => ['file_path' => 'voice/1.oga']],
        'file' => Http::response('OggS'),
    ]);
    app()->instance(Transcriber::class, new class implements Transcriber
    {
        public function transcribe(string $audio, string $filename): string
        {
            return $audio === 'OggS' ? 'Принимаю все рекомендации' : 'не то аудио';
        }
    });

    app(Responder::class)->answer(waitingReply('', ['voiceFileId' => 'voice-1']));

    expect(end($mcp->comments['XY-1'])['text'])->toStartWith('Ответ разработчика (Telegram, голосовое сообщение):')
        ->and($mcp->issues['XY-1']['fields']['Stage'])->toBe('Backlog');
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_starts_with($job->text, '🎙 «Принимаю все рекомендации»'));
});

it('asks for text when a voice message cannot be transcribed', function () {
    respondingProject(['reply' => 'x']);
    fakeTelegram(['getFile' => ['ok' => true, 'result' => ['file_path' => 'voice/1.oga']], 'file' => Http::response('OggS')]);
    config(['agentio.telegram.transcription.driver' => null]);

    app(Responder::class)->answer(waitingReply('', ['voiceFileId' => 'voice-1']));

    Process::assertNothingRan();
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->kind === 'error' && str_contains($job->text, 'распознавание голосовых не настроено'));
});

it('still records an answer to a question when the assistant is unavailable', function () {
    $mcp = respondingProject('You have reached your usage limit', exitCode: 1);

    app(Responder::class)->answer(waitingReply('В1: б'));

    expect(end($mcp->comments['XY-1'])['text'])->toBe("Ответ разработчика (Telegram):\n\nВ1: б")
        ->and($mcp->issues['XY-1']['fields']['Stage'])->toBe('Blocked');
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'Ассистент сейчас недоступен (Claude Code failed (exit 1): You have reached your usage limit)'));
});

it('answers every waiting message in order and only once', function () {
    respondingProject(['reply' => 'Ок.']);
    $conversation = app(Conversation::class);
    $conversation->putInbox(new IncomingMessage(9, 1009, '42', 'Второе'));
    $conversation->putInbox(new IncomingMessage(3, 1003, '42', 'Первое'));

    expect(app(Responder::class)->answer('9'))->toBeTrue()
        ->and(app(Responder::class)->answer('3'))->toBeFalse()
        ->and(array_column($conversation->history(), 'text'))->toBe(['Первое', 'Второе']);
    Queue::assertPushed(SendTelegramMessage::class, 2);
});

/**
 * A project whose bot is in godmode, with the Claude Code session answering as $results say (in order).
 *
 * @param  list<array{0: array<string, mixed>|string, 1?: int}>  $results  The JSON output (or the error output) and the exit code
 */
function godmodeProject(array $results): Conversation
{
    projectWithBot();
    Queue::fake();
    config(['agentio.telegram.godmode.enabled' => true, 'agentio.telegram.godmode.timeout' => 1200]);

    $sequence = Process::sequence();

    foreach ($results as $result) {
        $sequence->push(is_array($result[0])
            ? Process::result((string) json_encode(['type' => 'result', 'is_error' => false, ...$result[0]], JSON_UNESCAPED_UNICODE), exitCode: $result[1] ?? 0)
            : Process::result(errorOutput: $result[0], exitCode: $result[1] ?? 1));
    }

    Process::fake(['*--output-format*' => $sequence]);

    $conversation = app(Conversation::class);
    $conversation->startGodmode();

    return $conversation;
}

it('does a task of godmode in a full-access session and continues it with the next one', function () {
    $conversation = godmodeProject([
        [['result' => 'Поставил **spatie/laravel-data**, тесты зелёные.', 'session_id' => 'abc-1']],
        [['result' => 'Закоммитил.', 'session_id' => 'abc-1']],
    ]);
    $project = base_path();

    expect(app(Responder::class)->answer($conversation->putInbox(new IncomingMessage(8, 1008, '42', 'Поставь spatie/laravel-data', godmode: true))))->toBeTrue();

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && array_slice($process->command, 0, 6) === ['claude', '-p', 'Поставь spatie/laravel-data', '--output-format', 'json', '--dangerously-skip-permissions']
        && ! in_array('dontAsk', $process->command, true)
        && ! in_array('--settings', $process->command, true)
        && ! in_array('--resume', $process->command, true)
        && str_contains($process->command[array_search('--append-system-prompt', $process->command, true) + 1], 'проектом XY по сообщениям разработчика из Telegram-бота')
        && $process->path === $project
        && $process->timeout === 1200
        && $process->environment['YOUTRACK_TOKEN'] === 'secret-token'
        // The variables of the project's .env stay out of the session, as in the sessions of the loop.
        && $process->environment['APP_ENV'] === false);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->kind === 'godmode'
        && $job->replyTo === 1008
        && $job->text === '⚡ Поставил **spatie/laravel-data**, тесты зелёные.');

    expect($conversation->godmodeSession())->toBe('abc-1')
        ->and($conversation->inboxKeys())->toBe([])
        ->and($conversation->history()[0]['text'])->toBe('(godmode) Поставь spatie/laravel-data');

    app(Responder::class)->answer($conversation->putInbox(new IncomingMessage(9, 1009, '42', 'Закоммить', godmode: true)));

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && $process->command[2] === 'Закоммить'
        && array_slice($process->command, array_search('--resume', $process->command, true), 2) === ['--resume', 'abc-1']);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->replyTo === 1009 && $job->text === '⚡ Закоммитил.');
});

it('starts a new session when the session of godmode is gone', function () {
    $conversation = godmodeProject([
        ['No conversation found with session ID: abc-1'],
        [['result' => 'Готово.', 'session_id' => 'def-2']],
    ]);
    $conversation->continueGodmode('abc-1');

    app(Responder::class)->answer($conversation->putInbox(new IncomingMessage(8, 1008, '42', 'Прогони тесты', godmode: true)));

    Process::assertRanTimes(fn (PendingProcess $process): bool => is_array($process->command) && $process->command[2] === 'Прогони тесты', 2);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->text === '⚡ Готово.');
    expect($conversation->godmodeSession())->toBe('def-2');
});

it('reports a task of godmode that failed and stays in godmode', function () {
    $conversation = godmodeProject([['Credit balance is too low']]);

    app(Responder::class)->answer($conversation->putInbox(new IncomingMessage(8, 1008, '42', 'Прогони тесты', godmode: true)));

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->kind === 'error'
        && $job->replyTo === 1008
        && str_contains($job->text, 'Задача не выполнена: Claude Code failed (exit 1): Credit balance is too low'));
    expect($conversation->inGodmode(3600))->toBeTrue()->and($conversation->godmodeSession())->toBeNull();
});

it('reports a task of godmode that did not finish in time', function () {
    $conversation = godmodeProject([]);
    Process::fake(['*--output-format*' => fn () => throw new ProcessTimedOutException(
        new SymfonyProcessTimedOutException(new SymfonyProcess(['claude']), SymfonyProcessTimedOutException::TYPE_GENERAL),
        new FakeProcessResult,
    )]);

    app(Responder::class)->answer($conversation->putInbox(new IncomingMessage(8, 1008, '42', 'Перепиши всё', godmode: true)));

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->kind === 'error'
        && str_contains($job->text, 'Claude Code did not finish in 1200 seconds (AGENTIO_TELEGRAM_GODMODE_TIMEOUT)'));
});
