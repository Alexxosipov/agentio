<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Settings;

/**
 * The settings of the developer's Telegram bot (config agentio.telegram, from .env).
 */
final readonly class BotSettings
{
    public const string TOKEN = 'AGENTIO_TELEGRAM_BOT_TOKEN';

    public const string CHAT_ID = 'AGENTIO_TELEGRAM_CHAT_ID';

    /** The prefixes of the .env keys of the bot itself. */
    public const array ENV_PREFIXES = ['AGENTIO_TELEGRAM_', 'AGENTIO_TRANSCRIPTION_', 'AGENTIO_WHISPER_', 'AGENTIO_FFMPEG_'];

    /** The prefixes of the .env keys the bot's processes use (its own, the project's, YouTrack's): a change of any restarts them. */
    public const array RESTART_PREFIXES = ['AGENTIO_', 'YOUTRACK_'];

    public function __construct(
        public ?string $token,
        public ?string $chatId,
        public string $apiUrl = 'https://api.telegram.org',
        public string $queueConnection = 'redis',
        public string $queue = 'default',
        public int $watchInterval = 60,
        public int $assistantTimeout = 600,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            token: Settings::string('agentio.telegram.token'),
            chatId: Settings::string('agentio.telegram.chat_id'),
            apiUrl: Settings::string('agentio.telegram.api_url') ?? 'https://api.telegram.org',
            queueConnection: Settings::string('agentio.telegram.queue_connection') ?? 'redis',
            queue: Settings::string('agentio.telegram.queue') ?? 'default',
            watchInterval: max(10, (int) config('agentio.telegram.watch_interval', 60)),
            assistantTimeout: max(60, (int) config('agentio.telegram.assistant_timeout', 600)),
        );
    }

    /**
     * Whether a bot token is set.
     */
    public function isConfigured(): bool
    {
        return $this->token !== null;
    }

    /**
     * Whether the bot knows the developer's chat (paired).
     */
    public function isPaired(): bool
    {
        return $this->token !== null && $this->chatId !== null;
    }

    /**
     * Whether a key of .env belongs to the bot.
     */
    public static function isBotKey(string $key): bool
    {
        foreach (self::ENV_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A fingerprint of the .env keys the bot's processes use (RESTART_PREFIXES): it changes whenever one of them
     * is added, changed or removed.
     */
    public static function fingerprint(EnvFile $env): string
    {
        $values = [];

        foreach ($env->keys() as $key) {
            foreach (self::RESTART_PREFIXES as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $values[$key] = $env->get($key);
                }
            }
        }

        ksort($values);

        return hash('sha256', (string) json_encode($values));
    }
}
