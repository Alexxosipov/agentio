<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Transcription;

use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;

/**
 * Speech to text through an OpenAI-compatible /audio/transcriptions endpoint.
 */
final readonly class OpenAiTranscriber implements Transcriber
{
    public function __construct(
        private TranscriptionConnector $connector,
        private string $model = 'whisper-1',
        private ?string $language = 'ru',
    ) {}

    public function transcribe(string $audio, string $filename): string
    {
        // Telegram names voice files *.oga; the APIs know the format as .ogg.
        $filename = (string) preg_replace('/\.oga$/i', '.ogg', $filename);

        try {
            $response = $this->connector->send(new CreateTranscription($audio, $filename, $this->model, $this->language));
        } catch (FatalRequestException $exception) {
            throw new TranscriptionException('The transcription API is unreachable: '.$exception->getMessage(), 0, $exception);
        }

        try {
            $text = $response->json('text');
            $error = $response->json('error.message');
        } catch (JsonException) {
            [$text, $error] = [null, null];
        }

        if ($response->failed() || ! is_string($text)) {
            throw new TranscriptionException('The transcription API answered HTTP '.$response->status().(is_string($error) ? ': '.$error : '.'));
        }

        return trim($text);
    }
}
