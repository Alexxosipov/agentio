<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use RuntimeException;

/**
 * A failed call of the Telegram Bot API: Telegram's error code (0 when Telegram was not reached) and description.
 */
final class TelegramException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $errorCode = 0,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $errorCode);
    }

    /**
     * Another process reads the updates of the bot (409 Conflict): only one getUpdates may run per token.
     */
    public function isConflict(): bool
    {
        return $this->errorCode === 409;
    }

    /**
     * The token is wrong or revoked.
     */
    public function isUnauthorized(): bool
    {
        return $this->errorCode === 401 || $this->errorCode === 404;
    }

    /**
     * Whether trying again later may succeed: Telegram unreachable, flood control (429) or a server error.
     */
    public function isTemporary(): bool
    {
        return $this->errorCode === 0 || $this->errorCode === 429 || $this->errorCode >= 500;
    }
}
