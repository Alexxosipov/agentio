<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Api;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * The Telegram Bot API (https://core.telegram.org/bots/api): every method is <api>/bot<token>/<method>. The
 * token is part of the base URL, so it never appears in the requests or in an exception message of agentio.
 */
final class TelegramConnector extends Connector
{
    use AcceptsJson;
    use HasTimeout;

    protected int $connectTimeout = 10;

    protected int $requestTimeout = 30;

    public function __construct(
        private readonly string $token,
        private readonly string $apiUrl = 'https://api.telegram.org',
    ) {}

    public function resolveBaseUrl(): string
    {
        return rtrim($this->apiUrl, '/').'/bot'.$this->token;
    }
}
