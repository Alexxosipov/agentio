<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Review;

use RuntimeException;

/**
 * An acceptance action that could not run: the message is meant for the human in the dashboard,
 * $status is the HTTP status of the answer and $details lists what stood in the way.
 */
final class ReviewException extends RuntimeException
{
    /**
     * @param  list<string>  $details
     */
    public function __construct(string $message, public readonly int $status = 409, public readonly array $details = [])
    {
        parent::__construct($message, $status);
    }
}
