<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The usage limit of the Claude Code subscription a session ran into: the API refused its requests until
 * $resetsAt. Not a failure of the session: the loop pauses until the reset and resumes the work then.
 */
final readonly class UsageLimit
{
    /**
     * @param  CarbonImmutable|null  $resetsAt  When the limit resets (null when Claude Code did not say)
     * @param  string|null  $window  The limit that was hit: five_hour, seven_day, seven_day_opus, ...
     * @param  string  $message  The text of Claude Code's error ("You've hit your session limit · resets 6:40pm (Asia/Makassar)")
     */
    public function __construct(
        public ?CarbonImmutable $resetsAt,
        public ?string $window = null,
        public string $message = '',
    ) {}

    /**
     * From the rate_limit_info of a stream-json rate_limit_event.
     *
     * @param  array<array-key, mixed>  $info
     */
    public static function fromRateLimitInfo(array $info, string $message = ''): self
    {
        $resetsAt = $info['resetsAt'] ?? null;

        return new self(
            is_int($resetsAt) && $resetsAt > 0 ? CarbonImmutable::createFromTimestampUTC($resetsAt) : null,
            is_string($info['rateLimitType'] ?? null) && $info['rateLimitType'] !== '' ? $info['rateLimitType'] : null,
            $message,
        );
    }

    /**
     * From the text of Claude Code's error alone: "… · resets 6:40pm (Asia/Makassar)", "… resets Oct 9, 5pm (Europe/Moscow)"
     * or the older "Claude AI usage limit reached|1759856400". A time without a date is the next such time after $now;
     * the time zone in parentheses, else $timezone, else the one of $now.
     */
    public static function fromMessage(string $message, ?CarbonImmutable $now = null, ?string $timezone = null): self
    {
        $now ??= CarbonImmutable::now();

        if (preg_match('/\|(\d{9,11})\b/', $message, $match) === 1) {
            return new self(CarbonImmutable::createFromTimestampUTC((int) $match[1]), null, $message);
        }

        if (preg_match('/\bresets\s+(?:at\s+)?(.+?)\s*(?:\(([^)]+)\))?\s*$/iu', trim($message), $match) !== 1) {
            return new self(null, null, $message);
        }

        // "Oct 9, 5pm" → "Oct 9 5:00pm": the forms DateTime::modify() reads.
        $time = (string) preg_replace(['/,|\s+at\s+/i', '/\b(\d{1,2})\s*(am|pm)\b/i', '/\s+/'], [' ', '$1:00$2', ' '], $match[1]);
        $hasDate = preg_match('/[a-z]{3,}\.?\s+\d/i', $time) === 1;

        try {
            $resetsAt = $now->setTimezone($match[2] ?? $timezone ?? $now->getTimezone()->getName())->modify($time);
        } catch (Throwable) {
            return new self(null, null, $message);
        }

        if ($resetsAt->lessThanOrEqualTo($now)) {
            $resetsAt = $hasDate ? $resetsAt->addYear() : $resetsAt->addDay();
        }

        return new self($resetsAt->utc(), null, $message);
    }

    /**
     * The limit in Russian, for the reports of the bot.
     */
    public static function windowLabel(?string $window): string
    {
        return match (true) {
            $window === 'five_hour' => '5-часовой лимит',
            $window === 'seven_day' => 'недельный лимит',
            is_string($window) && str_starts_with($window, 'seven_day_') => 'недельный лимит модели',
            default => 'лимит',
        };
    }
}
