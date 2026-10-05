<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Api;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * The file storage of the Telegram Bot API: <api>/file/bot<token>/<file_path> (see GetFile).
 */
final class TelegramFileConnector extends Connector
{
    use HasTimeout;

    protected int $connectTimeout = 10;

    protected int $requestTimeout = 120;

    public function __construct(
        private readonly string $token,
        private readonly string $apiUrl = 'https://api.telegram.org',
    ) {}

    public function resolveBaseUrl(): string
    {
        return rtrim($this->apiUrl, '/').'/file/bot'.$this->token;
    }
}
