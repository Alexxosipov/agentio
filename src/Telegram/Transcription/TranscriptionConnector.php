<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Transcription;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * An OpenAI-compatible speech-to-text API (OpenAI, Groq, a local whisper server …) at its base URL, e.g.
 * https://api.openai.com/v1.
 */
final class TranscriptionConnector extends Connector
{
    use AcceptsJson;
    use HasTimeout;

    protected int $connectTimeout = 10;

    protected int $requestTimeout = 180;

    public function __construct(
        private readonly string $url,
        private readonly ?string $key = null,
    ) {}

    public function resolveBaseUrl(): string
    {
        return rtrim($this->url, '/');
    }

    protected function defaultAuth(): ?TokenAuthenticator
    {
        return $this->key === null ? null : new TokenAuthenticator($this->key);
    }
}
