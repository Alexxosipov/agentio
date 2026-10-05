<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Transcription;

use Saloon\Contracts\Body\HasBody;
use Saloon\Data\MultipartValue;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasMultipartBody;

/**
 * POST /audio/transcriptions: the audio file and the model; answers {"text": "…"}.
 */
final class CreateTranscription extends Request implements HasBody
{
    use HasMultipartBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $audio,
        private readonly string $filename,
        private readonly string $model,
        private readonly ?string $language = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/audio/transcriptions';
    }

    /**
     * @return list<MultipartValue>
     */
    protected function defaultBody(): array
    {
        $body = [
            new MultipartValue('file', $this->audio, $this->filename),
            new MultipartValue('model', $this->model),
            new MultipartValue('response_format', 'json'),
        ];

        if ($this->language !== null) {
            $body[] = new MultipartValue('language', $this->language);
        }

        return $body;
    }
}
