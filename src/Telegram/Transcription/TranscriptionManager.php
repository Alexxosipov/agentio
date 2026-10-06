<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Transcription;

use Illuminate\Support\Manager;
use Obrazmisli\Agentio\Runtime\LoopState;

/**
 * The transcription drivers (agentio.telegram.transcription.driver): "openai" (an OpenAI-compatible
 * /audio/transcriptions endpoint) and "whisper" (the local whisper.cpp CLI); without a driver voice messages are
 * not transcribed ("none"). An application adds its own with extend():
 *
 *     app(TranscriptionManager::class)->extend('yandex', fn ($app) => new YandexTranscriber(...));
 */
final class TranscriptionManager extends Manager
{
    public const string OPENAI = 'openai';

    public const string WHISPER = 'whisper';

    public const string NONE = 'none';

    public function getDefaultDriver(): string
    {
        $driver = $this->option('driver') ?? self::NONE;

        // An unknown driver fails only the voice messages, with the reason, not every message.
        return in_array($driver, [self::OPENAI, self::WHISPER, self::NONE], true) || isset($this->customCreators[$driver]) ? $driver : self::NONE;
    }

    public function createOpenaiDriver(): Transcriber
    {
        return new OpenAiTranscriber(
            url: $this->option('url') ?? 'https://api.openai.com/v1',
            key: $this->option('key'),
            model: $this->option('model') ?? 'whisper-1',
            language: $this->option('language'),
        );
    }

    public function createWhisperDriver(): Transcriber
    {
        return new WhisperCliTranscriber(
            binary: $this->option('whisper_binary') ?? 'whisper-cli',
            model: $this->option('whisper_model') ?? '',
            ffmpeg: $this->option('ffmpeg_binary') ?? 'ffmpeg',
            workDirectory: $this->container->make(LoopState::class)->logsPath().'/telegram',
            language: $this->option('language'),
        );
    }

    public function createNoneDriver(): Transcriber
    {
        $driver = $this->option('driver');

        return new NullTranscriber($driver === null || $driver === self::NONE ? null : "неизвестный драйвер {$driver}");
    }

    private function option(string $key): ?string
    {
        $value = $this->config->get('agentio.telegram.transcription.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
