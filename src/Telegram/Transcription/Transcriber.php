<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Transcription;

/**
 * Turns a voice message of the developer into text.
 */
interface Transcriber
{
    /**
     * @param  string  $audio  The content of the file (Telegram voice messages are OGG/Opus)
     * @param  string  $filename  Its name, e.g. "voice.oga"
     *
     * @throws TranscriptionException
     */
    public function transcribe(string $audio, string $filename): string;
}
