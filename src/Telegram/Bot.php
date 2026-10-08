<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The calls of the Telegram Bot API (https://core.telegram.org/bots/api) agentio makes, over the HTTP client of
 * Laravel: the answer's "result", or a TelegramException with Telegram's error code and description.
 *
 * Every method is <api>/bot<token>/<method>, a file is <api>/file/bot<token>/<file_path>. The token is part of
 * the URL, so it never appears in an exception message of agentio.
 */
final readonly class Bot
{
    /** The largest file the Bot API lets a bot download. */
    public const int MAX_DOWNLOAD = 20 * 1024 * 1024;

    private const int CONNECT_TIMEOUT = 10;

    private const int TIMEOUT = 30;

    private const int DOWNLOAD_TIMEOUT = 120;

    public function __construct(
        private string $token,
        private string $apiUrl = 'https://api.telegram.org',
    ) {}

    public static function make(string $token, string $apiUrl = 'https://api.telegram.org'): self
    {
        return new self($token, $apiUrl);
    }

    /**
     * The bot the token belongs to.
     *
     * @return array{id: int, username: string, name: string}
     *
     * @throws TelegramException
     */
    public function me(): array
    {
        $me = $this->result(fn (PendingRequest $request): Response => $request->get('getMe'));
        $me = is_array($me) ? $me : [];

        return [
            'id' => is_int($me['id'] ?? null) ? $me['id'] : 0,
            'username' => is_string($me['username'] ?? null) ? $me['username'] : '',
            'name' => is_string($me['first_name'] ?? null) ? $me['first_name'] : '',
        ];
    }

    /**
     * The updates after the offset, waiting up to $timeout seconds for one (long polling: an offset confirms
     * every update before it, so Telegram never sends them again).
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws TelegramException
     */
    public function updates(?int $offset, int $timeout = 25): array
    {
        $updates = $this->call('getUpdates', array_filter([
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ], fn (mixed $value): bool => $value !== null), $timeout + 15);

        return array_values(array_filter(is_array($updates) ? $updates : [], is_array(...)));
    }

    /**
     * Send a text (Markdown of the agents, see TelegramText), split into as many messages as it needs (at most
     * 4096 characters each); the first one replies to $replyTo, the last one carries $markup (buttons under it, or a
     * request for a reply). A message Telegram cannot parse as HTML is sent as plain text. Returns the ids of the
     * sent messages.
     *
     * @param  array<string, mixed>|null  $markup  The reply_markup of the Bot API: inline_keyboard or force_reply
     * @return list<int>
     *
     * @throws TelegramException
     */
    public function send(string $chatId, string $text, ?int $replyTo = null, ?array $markup = null): array
    {
        $ids = [];
        $chunks = TelegramText::chunks($text);

        foreach ($chunks as $index => $chunk) {
            $last = $index === array_key_last($chunks) ? $markup : null;

            try {
                $message = $this->call('sendMessage', self::message($chatId, TelegramText::html($chunk), 'HTML', $replyTo, $last));
            } catch (TelegramException $exception) {
                if ($exception->errorCode !== 400 || ! str_contains(strtolower($exception->getMessage()), 'parse')) {
                    throw $exception;
                }

                $message = $this->call('sendMessage', self::message($chatId, TelegramText::plain($chunk), null, $replyTo, $last));
            }

            if (is_array($message) && is_int($message['message_id'] ?? null)) {
                $ids[] = $message['message_id'];
            }

            $replyTo = null;
        }

        return $ids;
    }

    /**
     * Answer the press of a button: Telegram stops the spinner on it and shows $text briefly (as an alert window
     * with $alert).
     *
     * @throws TelegramException
     */
    public function answerButton(string $callbackId, string $text = '', bool $alert = false): void
    {
        $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackId,
            'text' => $text === '' ? null : mb_substr($text, 0, 200),
            'show_alert' => $alert ? true : null,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Remove the buttons under a message of the bot.
     *
     * @throws TelegramException
     */
    public function removeButtons(string $chatId, int $messageId): void
    {
        $this->call('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => ['inline_keyboard' => []]]);
    }

    /**
     * Show "typing…" in the chat (for about five seconds).
     *
     * @throws TelegramException
     */
    public function typing(string $chatId): void
    {
        $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
    }

    /**
     * The content of a file the developer sent (a voice message).
     *
     * @throws TelegramException
     */
    public function download(string $fileId): string
    {
        $file = $this->call('getFile', ['file_id' => $fileId]);
        $path = is_array($file) && is_string($file['file_path'] ?? null) ? $file['file_path'] : null;

        if ($path === null) {
            throw new TelegramException('Telegram returned no path for the file (larger than '.(self::MAX_DOWNLOAD / 1024 / 1024).' MB?).');
        }

        $response = $this->reach(fn (): Response => Http::baseUrl($this->baseUrl('file/bot'))
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::DOWNLOAD_TIMEOUT)
            ->get(implode('/', array_map(rawurlencode(...), explode('/', ltrim($path, '/'))))));

        if ($response->failed()) {
            throw new TelegramException('Cannot download the file from Telegram (HTTP '.$response->status().').', $response->status());
        }

        return $response->body();
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws TelegramException
     */
    private function call(string $method, array $payload, int $timeout = self::TIMEOUT): mixed
    {
        return $this->result(fn (PendingRequest $request): Response => $request->timeout($timeout)->post($method, $payload));
    }

    /**
     * @param  Closure(PendingRequest): Response  $send
     *
     * @throws TelegramException
     */
    private function result(Closure $send): mixed
    {
        $response = $this->reach(fn (): Response => $send(Http::baseUrl($this->baseUrl('bot'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)));

        $data = $response->json();

        if (! is_array($data)) {
            throw new TelegramException('Telegram answered HTTP '.$response->status().' without JSON.', $response->status());
        }

        if (($data['ok'] ?? false) !== true) {
            $parameters = is_array($data['parameters'] ?? null) ? $data['parameters'] : [];

            throw new TelegramException(
                'Telegram: '.(is_string($data['description'] ?? null) ? $data['description'] : 'HTTP '.$response->status()),
                is_int($data['error_code'] ?? null) ? $data['error_code'] : $response->status(),
                is_int($parameters['retry_after'] ?? null) ? $parameters['retry_after'] : null,
            );
        }

        return $data['result'] ?? null;
    }

    /**
     * @param  Closure(): Response  $send
     *
     * @throws TelegramException
     */
    private function reach(Closure $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $exception) {
            // The message of the HTTP client names the URL, which holds the token.
            throw new TelegramException('Cannot reach Telegram: '.self::withoutUrls($exception->getMessage()));
        }
    }

    /**
     * The URL of the methods ("bot") or of the files ("file/bot") of this bot, with a trailing slash.
     */
    private function baseUrl(string $prefix): string
    {
        return rtrim($this->apiUrl, '/').'/'.$prefix.$this->token.'/';
    }

    /**
     * @param  array<string, mixed>|null  $markup
     * @return array<string, mixed>
     */
    private static function message(string $chatId, string $text, ?string $parseMode, ?int $replyTo, ?array $markup = null): array
    {
        return array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'link_preview_options' => ['is_disabled' => true],
            'reply_parameters' => $replyTo === null ? null : ['message_id' => $replyTo, 'allow_sending_without_reply' => true],
            'reply_markup' => $markup,
        ], fn (mixed $value): bool => $value !== null);
    }

    private static function withoutUrls(string $message): string
    {
        return (string) preg_replace('#https?://\S+#', '<telegram>', $message);
    }
}
