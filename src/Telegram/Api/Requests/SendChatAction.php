<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Api\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Shows the developer that the bot is busy ("typing…") for about five seconds.
 */
final class SendChatAction extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $chatId,
        private readonly string $action = 'typing',
    ) {}

    public function resolveEndpoint(): string
    {
        return '/sendChatAction';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return ['chat_id' => $this->chatId, 'action' => $this->action];
    }
}
