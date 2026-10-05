<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Api\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * The content of a file by the file_path GetFile returned (sent with TelegramFileConnector).
 */
final class DownloadFile extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $path) {}

    public function resolveEndpoint(): string
    {
        return '/'.implode('/', array_map(rawurlencode(...), explode('/', ltrim($this->path, '/'))));
    }
}
