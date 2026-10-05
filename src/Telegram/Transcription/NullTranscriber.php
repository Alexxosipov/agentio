<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Transcription;

/**
 * Voice messages are not set up: every transcription fails with a hint.
 */
final readonly class NullTranscriber implements Transcriber
{
    public function __construct(private ?string $reason = null) {}

    public function transcribe(string $audio, string $filename): string
    {
        throw new TranscriptionException('распознавание голосовых не настроено'.($this->reason === null ? '' : " ({$this->reason})").': php artisan agentio:setup-telegram --transcription=openai|whisper');
    }
}
