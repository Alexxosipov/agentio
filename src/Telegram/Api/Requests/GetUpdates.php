<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Api\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Long polling: waits up to $timeout seconds for the updates after $offset (an offset confirms every update
 * before it, so Telegram never sends them again).
 */
final class GetUpdates extends Request implements HasBody
{
    use HasJsonBody;
    use HasTimeout;

    protected Method $method = Method::POST;

    protected int $requestTimeout;

    public function __construct(
        private readonly ?int $offset = null,
        private readonly int $timeout = 25,
    ) {
        $this->requestTimeout = $timeout + 15;
    }

    public function resolveEndpoint(): string
    {
        return '/getUpdates';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'offset' => $this->offset,
            'timeout' => $this->timeout,
            'allowed_updates' => ['message'],
        ], fn (mixed $value): bool => $value !== null);
    }
}
