<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Api\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * The bot itself: {id, is_bot, first_name, username}. Checks the token.
 */
final class GetMe extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/getMe';
    }
}
