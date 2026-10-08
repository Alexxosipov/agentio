<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Obrazmisli\Agentio\Telegram\Bot;
use Obrazmisli\Agentio\Telegram\TelegramException;

function bot(): Bot
{
    return Bot::make('123:SECRET', 'https://telegram.test');
}

it('checks the token and reads the bot', function () {
    fakeTelegram(['getMe' => ['ok' => true, 'result' => ['id' => 7, 'is_bot' => true, 'first_name' => 'Agentio', 'username' => 'agentio_bot']]]);

    expect(bot()->me())->toBe(['id' => 7, 'username' => 'agentio_bot', 'name' => 'Agentio']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'https://telegram.test/bot123:SECRET/getMe');
});

it('reads updates by long polling after the offset', function () {
    fakeTelegram(['getUpdates' => ['ok' => true, 'result' => [telegramUpdate(5), 'junk']]]);

    expect(bot()->updates(5, 10))->toBe([telegramUpdate(5)]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://telegram.test/bot123:SECRET/getUpdates'
        && $request->data() === ['offset' => 5, 'timeout' => 10, 'allowed_updates' => ['message', 'callback_query']]);
});

it('sends a long text in several HTML messages, the first one as a reply', function () {
    fakeTelegram(['sendMessage' => Http::sequence()
        ->push(['ok' => true, 'result' => ['message_id' => 101]])
        ->push(['ok' => true, 'result' => ['message_id' => 102]])]);

    $ids = bot()->send('42', str_repeat("**Абзац** текста\n\n", 300), 77);

    expect($ids)->toBe([101, 102]);
    Http::assertSentCount(2);

    $bodies = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->values()->all();

    expect($bodies[0])->toMatchArray(['chat_id' => '42', 'parse_mode' => 'HTML', 'reply_parameters' => ['message_id' => 77, 'allow_sending_without_reply' => true]])
        ->and($bodies[0]['text'])->toStartWith('<b>Абзац</b> текста')
        ->and($bodies[1])->not->toHaveKey('reply_parameters');
});

it('puts the buttons under the last message of a long text', function () {
    fakeTelegram(['sendMessage' => Http::sequence()
        ->push(['ok' => true, 'result' => ['message_id' => 101]])
        ->push(['ok' => true, 'result' => ['message_id' => 102]])]);
    $buttons = ['inline_keyboard' => [[['text' => 'Да', 'callback_data' => 'accept:XY-1']]]];

    bot()->send('42', str_repeat("Абзац текста\n\n", 400), markup: $buttons);

    $bodies = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->values()->all();

    expect($bodies)->toHaveCount(2)
        ->and($bodies[0])->not->toHaveKey('reply_markup')
        ->and($bodies[1]['reply_markup'])->toBe($buttons);
});

it('answers the press of a button and removes the buttons', function () {
    fakeTelegram(['answerCallbackQuery' => ['ok' => true, 'result' => true], 'editMessageReplyMarkup' => ['ok' => true, 'result' => true]]);

    bot()->answerButton('cb-1', 'Принято', alert: true);
    bot()->removeButtons('42', 77);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
        && $request->data() === ['callback_query_id' => 'cb-1', 'text' => 'Принято', 'show_alert' => true]);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageReplyMarkup')
        && $request->data() === ['chat_id' => '42', 'message_id' => 77, 'reply_markup' => ['inline_keyboard' => []]]);
});

it('sends plain text when Telegram cannot parse the HTML', function () {
    fakeTelegram(['sendMessage' => fn (Request $request) => isset($request['parse_mode'])
        ? Http::response(['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"], 400)
        : Http::response(['ok' => true, 'result' => ['message_id' => 9]])]);

    expect(bot()->send('42', '**Жирный** `код`'))->toBe([9]);
    expect(Http::recorded()->last()[0]['text'])->toBe('Жирный код');
});

it('turns the errors of Telegram into exceptions with the code and retry_after', function () {
    fakeTelegram(['sendMessage' => Http::response(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 7', 'parameters' => ['retry_after' => 7]], 429)]);

    try {
        bot()->send('42', 'x');
        $this->fail('No exception');
    } catch (TelegramException $exception) {
        expect($exception->errorCode)->toBe(429)
            ->and($exception->retryAfter)->toBe(7)
            ->and($exception->isTemporary())->toBeTrue()
            ->and($exception->getMessage())->toBe('Telegram: Too Many Requests: retry after 7');
    }
});

it('reports an answer without JSON', function () {
    fakeTelegram(['getMe' => Http::response('<html>Bad Gateway</html>', 502)]);

    expect(fn () => bot()->me())->toThrow(function (TelegramException $exception): void {
        expect($exception->getMessage())->toBe('Telegram answered HTTP 502 without JSON.')->and($exception->isTemporary())->toBeTrue();
    });
});

it('never puts the token into the message of a connection error', function () {
    fakeTelegram(['getMe' => fn (Request $request) => throw new ConnectionException('cURL error 6: Could not resolve host: telegram.test for '.$request->url())]);

    expect(fn () => bot()->me())->toThrow(function (TelegramException $exception): void {
        expect($exception->getMessage())->toStartWith('Cannot reach Telegram: cURL error 6')
            ->not->toContain('SECRET')
            ->and($exception->errorCode)->toBe(0)
            ->and($exception->isTemporary())->toBeTrue();
    });
});

it('downloads a file the developer sent', function () {
    fakeTelegram([
        'getFile' => ['ok' => true, 'result' => ['file_id' => 'f', 'file_path' => 'voice/file_3.oga']],
        'file' => Http::response('OggS-audio'),
    ]);

    expect(bot()->download('f'))->toBe('OggS-audio');
    Http::assertSent(fn (Request $request): bool => $request->data() === ['file_id' => 'f']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://telegram.test/file/bot123:SECRET/voice/file_3.oga');
});
