<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * When a YouTrack request (REST or MCP) is sent again. A read is retried after any connection error, a 5xx or a
 * 429. A write (creating an issue, posting a comment) is retried only when YouTrack certainly did not get it —
 * the host was not resolved or the connection was refused, or the answer was 429 — because a timeout or a 5xx
 * may come after the write was done, and sending it again would create a second issue or comment.
 */
final class Retry
{
    /** cURL errors raised before the request was sent: could not resolve the host / connect. */
    private const string NOT_SENT = '/cURL error (6|7)\b/';

    /**
     * @return Closure(Throwable): bool
     */
    public static function when(bool $idempotent): Closure
    {
        return fn (Throwable $exception): bool => match (true) {
            $exception instanceof ConnectionException => $idempotent || preg_match(self::NOT_SENT, $exception->getMessage()) === 1,
            $exception instanceof RequestException => $exception->response->status() === 429 || ($idempotent && $exception->response->serverError()),
            default => false,
        };
    }

    public static function backoff(int $attempt): int
    {
        return $attempt * 500;
    }
}
