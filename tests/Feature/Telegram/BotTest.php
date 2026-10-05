<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Telegram\Api\Requests\DownloadFile;
use Obrazmisli\Agentio\Telegram\Api\Requests\GetFile;
use Obrazmisli\Agentio\Telegram\Api\Requests\GetMe;
use Obrazmisli\Agentio\Telegram\Api\Requests\GetUpdates;
use Obrazmisli\Agentio\Telegram\Api\Requests\SendMessage;
use Obrazmisli\Agentio\Telegram\Bot;
use Obrazmisli\Agentio\Telegram\TelegramException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;

function bot(): Bot
{
    return Bot::make('123:SECRET', 'https://telegram.test');
}

it('checks the token and reads the bot', function () {
    $mock = MockClient::global([GetMe::class => MockResponse::make(['ok' => true, 'result' => ['id' => 7, 'is_bot' => true, 'first_name' => 'Agentio', 'username' => 'agentio_bot']])]);

    expect(bot()->me())->toBe(['id' => 7, 'username' => 'agentio_bot', 'name' => 'Agentio']);
    $mock->assertSent(fn (Request $request, $response): bool => $response->getPendingRequest()->getUrl() === 'https://telegram.test/bot123:SECRET/getMe');
});

it('reads updates by long polling after the offset', function () {
    $mock = MockClient::global([GetUpdates::class => MockResponse::make(['ok' => true, 'result' => [telegramUpdate(5), 'junk']])]);

    expect(bot()->updates(5, 10))->toBe([telegramUpdate(5)]);
    $mock->assertSent(fn (Request $request): bool => $request instanceof GetUpdates && $request->body()->all() === ['offset' => 5, 'timeout' => 10, 'allowed_updates' => ['message']]);
});

it('sends a long text in several HTML messages, the first one as a reply', function () {
    $sequence = 0;
    $mock = MockClient::global([SendMessage::class => function () use (&$sequence): MockResponse {
        return MockResponse::make(['ok' => true, 'result' => ['message_id' => 100 + (++$sequence)]]);
    }]);

    $ids = bot()->send('42', str_repeat("**Абзац** текста\n\n", 300), 77);

    expect($ids)->toBe([101, 102]);
    $mock->assertSentCount(2);

    $bodies = array_map(fn ($response): array => $response->getPendingRequest()->body()->all(), $mock->getRecordedResponses());

    expect($bodies[0])->toMatchArray(['chat_id' => '42', 'parse_mode' => 'HTML', 'reply_parameters' => ['message_id' => 77, 'allow_sending_without_reply' => true]])
        ->and($bodies[0]['text'])->toStartWith('<b>Абзац</b> текста')
        ->and($bodies[1])->not->toHaveKey('reply_parameters');
});

it('sends plain text when Telegram cannot parse the HTML', function () {
    $mock = MockClient::global([SendMessage::class => function (PendingRequest $request): MockResponse {
        return $request->body()->all()['parse_mode'] ?? null
            ? MockResponse::make(['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"], 400)
            : MockResponse::make(['ok' => true, 'result' => ['message_id' => 9]]);
    }]);

    expect(bot()->send('42', '**Жирный** `код`'))->toBe([9]);
    expect($mock->getLastPendingRequest()?->body()->all()['text'])->toBe('Жирный код');
});

it('turns the errors of Telegram into exceptions with the code and retry_after', function () {
    MockClient::global([SendMessage::class => MockResponse::make(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 7', 'parameters' => ['retry_after' => 7]], 429)]);

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

it('never puts the token into the message of a connection error', function () {
    MockClient::global([GetMe::class => MockResponse::make()->throw(fn (PendingRequest $request) => new FatalRequestException(new RuntimeException('cURL error 6 for https://telegram.test/bot123:SECRET/getMe'), $request))]);

    expect(fn () => bot()->me())->toThrow(function (TelegramException $exception): void {
        expect($exception->getMessage())->not->toContain('SECRET')->and($exception->errorCode)->toBe(0)->and($exception->isTemporary())->toBeTrue();
    });
});

it('downloads a file the developer sent', function () {
    $mock = MockClient::global([
        GetFile::class => MockResponse::make(['ok' => true, 'result' => ['file_id' => 'f', 'file_path' => 'voice/file_3.oga']]),
        DownloadFile::class => MockResponse::make('OggS-audio'),
    ]);

    expect(bot()->download('f'))->toBe('OggS-audio');
    $mock->assertSent(fn (Request $request, $response): bool => $request instanceof DownloadFile && $response->getPendingRequest()->getUrl() === 'https://telegram.test/file/bot123:SECRET/voice/file_3.oga');
});
