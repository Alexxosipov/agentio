<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use JsonException;
use Obrazmisli\Agentio\Telegram\Api\Requests\DownloadFile;
use Obrazmisli\Agentio\Telegram\Api\Requests\GetFile;
use Obrazmisli\Agentio\Telegram\Api\Requests\GetMe;
use Obrazmisli\Agentio\Telegram\Api\Requests\GetUpdates;
use Obrazmisli\Agentio\Telegram\Api\Requests\SendChatAction;
use Obrazmisli\Agentio\Telegram\Api\Requests\SendMessage;
use Obrazmisli\Agentio\Telegram\Api\TelegramConnector;
use Obrazmisli\Agentio\Telegram\Api\TelegramFileConnector;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Connector;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * The calls of the Telegram Bot API agentio makes, over the Saloon connectors: the answer's "result", or a
 * TelegramException with Telegram's error code and description.
 */
final readonly class Bot
{
    /** The largest file the Bot API lets a bot download. */
    public const int MAX_DOWNLOAD = 20 * 1024 * 1024;

    public function __construct(
        private TelegramConnector $connector,
        private TelegramFileConnector $files,
    ) {}

    public static function make(string $token, string $apiUrl = 'https://api.telegram.org'): self
    {
        return new self(new TelegramConnector($token, $apiUrl), new TelegramFileConnector($token, $apiUrl));
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
        $me = $this->result(new GetMe);
        $me = is_array($me) ? $me : [];

        return [
            'id' => is_int($me['id'] ?? null) ? $me['id'] : 0,
            'username' => is_string($me['username'] ?? null) ? $me['username'] : '',
            'name' => is_string($me['first_name'] ?? null) ? $me['first_name'] : '',
        ];
    }

    /**
     * The updates after the offset, waiting up to $timeout seconds for one (long polling).
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws TelegramException
     */
    public function updates(?int $offset, int $timeout = 25): array
    {
        $updates = $this->result(new GetUpdates($offset, $timeout));

        return array_values(array_filter(is_array($updates) ? $updates : [], is_array(...)));
    }

    /**
     * Send a text (Markdown of the agents, see TelegramText), split into as many messages as it needs; the first
     * one replies to $replyTo. A message Telegram cannot parse as HTML is sent as plain text. Returns the ids
     * of the sent messages.
     *
     * @return list<int>
     *
     * @throws TelegramException
     */
    public function send(string $chatId, string $text, ?int $replyTo = null): array
    {
        $ids = [];

        foreach (TelegramText::chunks($text) as $chunk) {
            try {
                $message = $this->result(new SendMessage($chatId, TelegramText::html($chunk), 'HTML', $replyTo));
            } catch (TelegramException $exception) {
                if ($exception->errorCode !== 400 || ! str_contains(strtolower($exception->getMessage()), 'parse')) {
                    throw $exception;
                }

                $message = $this->result(new SendMessage($chatId, TelegramText::plain($chunk), null, $replyTo));
            }

            if (is_array($message) && is_int($message['message_id'] ?? null)) {
                $ids[] = $message['message_id'];
            }

            $replyTo = null;
        }

        return $ids;
    }

    /**
     * Show "typing…" in the chat.
     *
     * @throws TelegramException
     */
    public function typing(string $chatId): void
    {
        $this->result(new SendChatAction($chatId));
    }

    /**
     * The content of a file the developer sent (a voice message).
     *
     * @throws TelegramException
     */
    public function download(string $fileId): string
    {
        $file = $this->result(new GetFile($fileId));
        $path = is_array($file) && is_string($file['file_path'] ?? null) ? $file['file_path'] : null;

        if ($path === null) {
            throw new TelegramException('Telegram returned no path for the file (larger than '.(self::MAX_DOWNLOAD / 1024 / 1024).' MB?).');
        }

        $response = $this->sendTo($this->files, new DownloadFile($path));

        if ($response->failed()) {
            throw new TelegramException('Cannot download the file from Telegram (HTTP '.$response->status().').', $response->status());
        }

        return $response->body();
    }

    /**
     * @throws TelegramException
     */
    private function result(Request $request): mixed
    {
        $response = $this->sendTo($this->connector, $request);

        try {
            $data = $response->json();
        } catch (JsonException) {
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
     * @throws TelegramException
     */
    private function sendTo(Connector $connector, Request $request): Response
    {
        try {
            return $connector->send($request);
        } catch (FatalRequestException $exception) {
            // The message of Guzzle names the URL, which holds the token.
            throw new TelegramException('Cannot reach Telegram: '.self::withoutUrls($exception->getMessage()));
        }
    }

    private static function withoutUrls(string $message): string
    {
        return (string) preg_replace('#https?://\S+#', '<telegram>', $message);
    }
}
