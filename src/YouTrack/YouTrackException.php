<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack;

use RuntimeException;
use Throwable;

final class YouTrackException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self('YouTrack is not configured: set YOUTRACK_URL and YOUTRACK_TOKEN (config agentio.youtrack.url / agentio.youtrack.token).');
    }

    public static function requestFailed(string $method, string $path, int $status, string $reason): self
    {
        return new self(sprintf('YouTrack %s %s failed with HTTP %d: %s', $method, $path, $status, $reason), $status);
    }

    public static function connectionFailed(string $method, string $path, Throwable $previous): self
    {
        return new self(sprintf('YouTrack %s %s failed: %s', $method, $path, $previous->getMessage()), null, $previous);
    }

    public static function projectNotFound(string $shortName): self
    {
        return new self(sprintf('YouTrack project "%s" was not found or is not visible to the token.', $shortName), 404);
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
