<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Api\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * One text message (at most 4096 characters after the entities are parsed), optionally a reply.
 */
final class SendMessage extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $chatId,
        private readonly string $text,
        private readonly ?string $parseMode = null,
        private readonly ?int $replyTo = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/sendMessage';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'chat_id' => $this->chatId,
            'text' => $this->text,
            'parse_mode' => $this->parseMode,
            'link_preview_options' => ['is_disabled' => true],
            'reply_parameters' => $this->replyTo === null ? null : ['message_id' => $this->replyTo, 'allow_sending_without_reply' => true],
        ], fn (mixed $value): bool => $value !== null);
    }
}
