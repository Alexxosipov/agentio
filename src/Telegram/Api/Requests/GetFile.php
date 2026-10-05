<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Api\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * The path of a file (a voice message) for DownloadFile: {file_id, file_size, file_path}.
 */
final class GetFile extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(private readonly string $fileId) {}

    public function resolveEndpoint(): string
    {
        return '/getFile';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return ['file_id' => $this->fileId];
    }
}
