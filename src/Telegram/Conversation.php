<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Closure;
use JsonException;

/**
 * The memory of the bot, in <logs>/telegram (local to the machine that runs agentio:run, like the loop's files):
 *
 * - state.json: the update offset of getUpdates, the time of the last agent comment the watcher saw, a pending
 *   pairing code, the release pull request waiting for the developer's confirmation;
 * - messages.json: what every message the bot sent is about (the issue, the kind — question, report, answer —
 *   and the Stage the issue returns to), so a reply of the developer is tied to its issue;
 * - history.json: the last messages of the conversation, the context of the assistant;
 * - inbox/<update>.json: a message waiting for the assistant.
 *
 * Every read-modify-write holds an exclusive lock: the listener, the queue workers and the assistant share it.
 */
final readonly class Conversation
{
    /** How many sent messages are remembered for replies. */
    public const int MESSAGES = 1000;

    /** How many messages of the conversation are kept for the assistant. */
    public const int HISTORY = 40;

    /** How long a pairing code is valid, in seconds. */
    public const int PAIRING_TTL = 3600;

    /** How long the developer may confirm a release after the bot asked, in seconds. */
    public const int RELEASE_TTL = 86400;

    public function __construct(private string $directory) {}

    public function directory(): string
    {
        return $this->directory;
    }

    public function offset(): ?int
    {
        $offset = $this->read('state')['offset'] ?? null;

        return is_int($offset) ? $offset : null;
    }

    public function setOffset(int $offset): void
    {
        $this->update('state', fn (array $state): array => [...$state, 'offset' => $offset]);
    }

    /**
     * The creation time (ms) of the newest agent comment the watcher has seen, null before its first run.
     */
    public function watermark(): ?int
    {
        $watermark = $this->read('state')['watermark'] ?? null;

        return is_int($watermark) ? $watermark : null;
    }

    public function setWatermark(int $milliseconds): void
    {
        $this->update('state', fn (array $state): array => [...$state, 'watermark' => $milliseconds]);
    }

    /**
     * A new pairing code: the developer sends /start <code> to the bot, which then remembers the chat.
     */
    public function startPairing(): string
    {
        $code = bin2hex(random_bytes(4));
        $this->update('state', fn (array $state): array => [...$state, 'pairing' => ['code' => $code, 'expires' => time() + self::PAIRING_TTL]]);

        return $code;
    }

    /**
     * The pending pairing code, or null when there is none or it expired.
     */
    public function pairingCode(): ?string
    {
        $pairing = $this->read('state')['pairing'] ?? null;

        return is_array($pairing) && is_string($pairing['code'] ?? null) && (int) ($pairing['expires'] ?? 0) >= time() ? $pairing['code'] : null;
    }

    public function finishPairing(): void
    {
        $this->update('state', function (array $state): array {
            unset($state['pairing']);

            return $state;
        });
    }

    /**
     * The release pull request the bot asked the developer to confirm, at the commit of the development branch
     * the question was about.
     */
    public function askRelease(int $number, string $head, string $url): void
    {
        $this->update('state', fn (array $state): array => [...$state, 'release' => ['number' => $number, 'head' => $head, 'url' => $url, 'expires' => time() + self::RELEASE_TTL]]);
    }

    /**
     * The release waiting for the confirmation, or null when the bot asked none (or long ago).
     *
     * @return array{number: int, head: string, url: string}|null
     */
    public function pendingRelease(): ?array
    {
        $release = $this->read('state')['release'] ?? null;

        if (! is_array($release) || ! is_int($release['number'] ?? null) || ! is_string($release['head'] ?? null) || (int) ($release['expires'] ?? 0) < time()) {
            return null;
        }

        return ['number' => $release['number'], 'head' => $release['head'], 'url' => is_string($release['url'] ?? null) ? $release['url'] : ''];
    }

    public function forgetRelease(): void
    {
        $this->update('state', function (array $state): array {
            unset($state['release']);

            return $state;
        });
    }

    /**
     * Remember what the sent messages are about.
     *
     * @param  list<int>  $messageIds
     */
    public function remember(array $messageIds, string $kind, ?string $issue = null, ?string $stage = null): void
    {
        if ($messageIds === []) {
            return;
        }

        $this->update('messages', function (array $messages) use ($messageIds, $kind, $issue, $stage): array {
            foreach ($messageIds as $id) {
                $messages[(string) $id] = ['kind' => $kind, 'issue' => $issue, 'stage' => $stage, 'at' => time()];
            }

            return array_slice($messages, -self::MESSAGES, null, true);
        });
    }

    /**
     * What a message the bot sent is about, or null when it is not known.
     *
     * @return array{kind: string, issue: string|null, stage: string|null}|null
     */
    public function about(int $messageId): ?array
    {
        $message = $this->read('messages')[(string) $messageId] ?? null;

        if (! is_array($message) || ! is_string($message['kind'] ?? null)) {
            return null;
        }

        return [
            'kind' => $message['kind'],
            'issue' => is_string($message['issue'] ?? null) ? $message['issue'] : null,
            'stage' => is_string($message['stage'] ?? null) ? $message['stage'] : null,
        ];
    }

    /**
     * Add a message to the history: "developer" or "bot".
     */
    public function addHistory(string $role, string $text, ?string $issue = null): void
    {
        $entry = ['role' => $role, 'text' => TelegramText::limit($text, 2000), 'issue' => $issue, 'at' => date('Y-m-d H:i')];

        $this->update('history', fn (array $history): array => array_slice([...array_values($history), $entry], -self::HISTORY));
    }

    /**
     * The last messages of the conversation, oldest first.
     *
     * @return list<array{role: string, text: string, issue: string|null, at: string}>
     */
    public function history(int $limit = 20): array
    {
        $history = [];

        foreach (array_slice(array_values($this->read('history')), -$limit) as $entry) {
            if (is_array($entry) && is_string($entry['role'] ?? null) && is_string($entry['text'] ?? null)) {
                $history[] = [
                    'role' => $entry['role'],
                    'text' => $entry['text'],
                    'issue' => is_string($entry['issue'] ?? null) ? $entry['issue'] : null,
                    'at' => is_string($entry['at'] ?? null) ? $entry['at'] : '',
                ];
            }
        }

        return $history;
    }

    /**
     * Keep a message for the assistant; returns its key.
     */
    public function putInbox(IncomingMessage $message): string
    {
        $key = (string) $message->updateId;
        $this->ensureDirectory($this->directory.'/inbox');
        file_put_contents($this->inboxPath($key), (string) json_encode($message->toArray(), JSON_UNESCAPED_UNICODE));

        return $key;
    }

    public function inbox(string $key): ?IncomingMessage
    {
        if (preg_match('/^\d+$/', $key) !== 1 || ! is_file($this->inboxPath($key))) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->inboxPath($key)), true);

        return is_array($data) ? IncomingMessage::fromArray($data) : null;
    }

    /**
     * The keys of the messages waiting for the assistant, oldest first.
     *
     * @return list<string>
     */
    public function inboxKeys(): array
    {
        $keys = array_map(fn (string $file): string => basename($file, '.json'), glob($this->directory.'/inbox/*.json') ?: []);
        $keys = array_values(array_filter($keys, fn (string $key): bool => preg_match('/^\d+$/', $key) === 1));
        sort($keys, SORT_NUMERIC);

        return $keys;
    }

    public function forgetInbox(string $key): void
    {
        if (preg_match('/^\d+$/', $key) === 1 && is_file($this->inboxPath($key))) {
            unlink($this->inboxPath($key));
        }
    }

    /**
     * The lock file the assistant holds while it answers: messages are answered one at a time, in order.
     */
    public function assistantLock(): string
    {
        $this->ensureDirectory($this->directory);

        return $this->directory.'/assistant.lock';
    }

    private function inboxPath(string $key): string
    {
        return $this->directory.'/inbox/'.$key.'.json';
    }

    /**
     * @return array<array-key, mixed>
     */
    private function read(string $name): array
    {
        $file = $this->directory.'/'.$name.'.json';

        if (! is_file($file)) {
            return [];
        }

        return $this->locked(fn (): array => self::decode((string) file_get_contents($file)), LOCK_SH);
    }

    /**
     * @param  Closure(array<array-key, mixed>): array<array-key, mixed>  $change
     */
    private function update(string $name, Closure $change): void
    {
        $file = $this->directory.'/'.$name.'.json';

        $this->locked(function () use ($file, $change): array {
            $data = $change(is_file($file) ? self::decode((string) file_get_contents($file)) : []);
            file_put_contents($file, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

            return $data;
        });
    }

    /**
     * @param  Closure(): array<array-key, mixed>  $callback
     * @param  LOCK_SH|LOCK_EX  $operation
     * @return array<array-key, mixed>
     */
    private function locked(Closure $callback, int $operation = LOCK_EX): array
    {
        $this->ensureDirectory($this->directory);
        $handle = fopen($this->directory.'/.lock', 'c');

        if ($handle === false) {
            return $callback();
        }

        try {
            flock($handle, $operation);

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
    }
}
