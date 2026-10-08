<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Obrazmisli\Agentio\Telegram\Conversation;
use Obrazmisli\Agentio\Telegram\Jobs\HandleTelegramUpdate;
use Obrazmisli\Agentio\Telegram\Jobs\SendTelegramMessage;
use Obrazmisli\Agentio\Tests\Fakes\FakeYouTrackMcp;

/**
 * The press of a button under the message 500 of the bot (sent at $sentAt, a Unix time), as getUpdates returns it.
 *
 * @return array<string, mixed>
 */
function buttonPress(int $updateId, string $data, string $chatId = '42', int $sentAt = 1_791_000_100): array
{
    return [
        'update_id' => $updateId,
        'callback_query' => [
            'id' => 'cb-'.$updateId,
            'from' => ['id' => (int) $chatId, 'is_bot' => false, 'first_name' => 'Dev', 'username' => 'dev'],
            'message' => ['message_id' => 500, 'date' => $sentAt, 'chat' => ['id' => (int) $chatId, 'type' => 'private'], 'text' => '❓ Вопросы по XY-1'],
            'data' => $data,
        ],
    ];
}

/**
 * The bot paired with the chat 42, Telegram faked, and the idea XY-1 blocked on questions asked before the message
 * with the buttons was sent.
 */
function projectWithQuestions(string $state = 'Blocked'): FakeYouTrackMcp
{
    projectWithBot();
    Queue::fake();
    fakeTelegram(['answerCallbackQuery' => ['ok' => true, 'result' => true], 'editMessageReplyMarkup' => ['ok' => true, 'result' => true]]);

    return (new FakeYouTrackMcp)
        ->issue('XY-1', 'Idea', $state, tags: ['idea'], summary: '[IDEA] Оплата')
        ->comment('XY-1', "[AGENT:BLOCKED]\n**Что остановлено:** планирование.\n**Нужен ответ:** 1 вопрос, затем Stage → Backlog.\n\n**В1. Когда считать заказ оплаченным?**\n- **Рекомендация: б**")
        ->fake();
}

function pressButton(array $update): void
{
    app()->call([new HandleTelegramUpdate($update), 'handle']);
}

it('accepts every recommendation with the button and returns the issue to work', function () {
    $mcp = projectWithQuestions();

    pressButton(buttonPress(1, 'accept:XY-1'));

    expect((string) end($mcp->comments['XY-1'])['text'])->toStartWith("Ответ разработчика (Telegram):\n\nПринимаю рекомендации (кнопка «Принять рекомендации» под вопросами).")
        ->and((string) end($mcp->comments['XY-1'])['text'])->not->toContain('Исходное сообщение')
        ->and($mcp->issues['XY-1']['fields']['Stage'])->toBe('Backlog');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery') && $request['text'] === 'Рекомендации приняты.');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageReplyMarkup') && $request['message_id'] === 500);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->replyTo === 500 && $job->issue === 'XY-1' && str_contains($job->text, 'задача возвращена в Backlog'));
    expect(app(Conversation::class)->history()[0])->toMatchArray(['role' => 'developer', 'text' => 'Принимаю рекомендации', 'issue' => 'XY-1']);
});

it('does nothing when the issue no longer waits for an answer, or was asked again since', function () {
    $mcp = projectWithQuestions('Backlog');

    pressButton(buttonPress(1, 'accept:XY-1'));

    expect($mcp->comments['XY-1'])->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery') && str_contains((string) $request['text'], 'XY-1 уже не ждёт ответа (сейчас Backlog)'));

    $mcp->issues['XY-1']['fields']['Stage'] = 'Blocked';
    // The message with the button was sent before the questions it would answer were asked.
    pressButton(buttonPress(2, 'accept:XY-1', sentAt: 1_790_000_000));

    expect($mcp->comments['XY-1'])->toHaveCount(1)
        ->and($mcp->issues['XY-1']['fields']['Stage'])->toBe('Blocked');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery') && str_contains((string) $request['text'], 'агенты задали новые вопросы'));
    Queue::assertNotPushed(SendTelegramMessage::class);
});

it('asks for the answer in a message tied to the issue', function () {
    projectWithQuestions();
    app(Conversation::class)->remember([500], 'question', 'XY-1', 'Backlog');

    pressButton(buttonPress(1, 'reply:XY-1'));

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->kind === 'question'
        && $job->issue === 'XY-1'
        && $job->stage === 'Backlog'
        && $job->replyTo === 500
        && ($job->markup['force_reply'] ?? false) === true
        && str_contains($job->text, 'Напишите ответ на вопросы по XY-1'));
});

it('ignores the buttons of other chats and unknown buttons', function () {
    $mcp = projectWithQuestions();

    pressButton(buttonPress(1, 'accept:XY-1', chatId: '99'));
    pressButton(buttonPress(2, 'delete:XY-1'));
    pressButton(buttonPress(3, 'accept:XY-1; drop'));

    expect($mcp->comments['XY-1'])->toHaveCount(1)
        ->and($mcp->callsOf('get_issue'))->toBe([]);
    Http::assertSentCount(3);
    Queue::assertNotPushed(SendTelegramMessage::class);
});
