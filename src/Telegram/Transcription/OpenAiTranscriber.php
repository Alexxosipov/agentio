<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Transcription;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Speech to text through an OpenAI-compatible API (OpenAI, Groq, a local whisper server …) at its base URL, e.g.
 * https://api.openai.com/v1: POST /audio/transcriptions with the audio file and the model answers {"text": "…"}.
 */
final readonly class OpenAiTranscriber implements Transcriber
{
    public function __construct(
        private string $url,
        private ?string $key = null,
        private string $model = 'whisper-1',
        private ?string $language = 'ru',
        private int $timeout = 180,
    ) {}

    public function transcribe(string $audio, string $filename): string
    {
        // Telegram names voice files *.oga; the APIs know the format as .ogg.
        $filename = (string) preg_replace('/\.oga$/i', '.ogg', $filename);

        try {
            $response = $this->request()
                ->attach('file', $audio, $filename)
                ->post('audio/transcriptions', array_filter([
                    'model' => $this->model,
                    'response_format' => 'json',
                    'language' => $this->language,
                ], fn (?string $value): bool => $value !== null));
        } catch (ConnectionException $exception) {
            throw new TranscriptionException('The transcription API is unreachable: '.$exception->getMessage(), 0, $exception);
        }

        $text = $response->json('text');
        $error = $response->json('error.message');

        if ($response->failed() || ! is_string($text)) {
            throw new TranscriptionException('The transcription API answered HTTP '.$response->status().(is_string($error) ? ': '.$error : '.'));
        }

        return trim($text);
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->url, '/').'/')
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout($this->timeout);

        return $this->key === null ? $request : $request->withToken($this->key);
    }
}
