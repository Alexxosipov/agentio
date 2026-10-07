<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Obrazmisli\Agentio\Runtime\UsageLimit;

it('reads the reset time and the limit of a rejected rate_limit_event', function () {
    $limit = UsageLimit::fromRateLimitInfo(['status' => 'rejected', 'resetsAt' => 1791196800, 'rateLimitType' => 'five_hour'], 'hit');

    expect($limit->resetsAt?->getTimestamp())->toBe(1791196800)
        ->and($limit->window)->toBe('five_hour')
        ->and($limit->message)->toBe('hit')
        ->and(UsageLimit::fromRateLimitInfo(['status' => 'rejected'])->resetsAt)->toBeNull();
});

it('reads the reset time from the text of the error', function (string $message, ?string $resetsAt) {
    $now = CarbonImmutable::parse('2026-10-05T07:22:50Z');

    expect(UsageLimit::fromMessage($message, $now, 'Europe/Moscow')->resetsAt?->toIso8601String())->toBe($resetsAt);
})->with([
    'time and zone' => ["You've hit your session limit · resets 6:40pm (Asia/Makassar)", '2026-10-05T10:40:00+00:00'],
    'time passed today' => ['5-hour limit reached ∙ resets 3am (Asia/Makassar)', '2026-10-05T19:00:00+00:00'],
    'time in the given zone' => ["You've hit your limit · resets 11:30am", '2026-10-05T08:30:00+00:00'],
    'date' => ['Weekly limit reached · resets Oct 9, 5pm (Europe/Moscow)', '2026-10-09T14:00:00+00:00'],
    'date of next year' => ['Weekly limit reached · resets Jan 2 at 5pm (UTC)', '2027-01-02T17:00:00+00:00'],
    'Unix time' => ['Claude AI usage limit reached|1791196800', '2026-10-05T10:40:00+00:00'],
    'no reset' => ['Rate limited', null],
    'unknown zone' => ['resets 6:40pm (Nowhere/Land)', null],
]);

it('names the limit in Russian', function () {
    expect(UsageLimit::windowLabel('five_hour'))->toBe('5-часовой лимит')
        ->and(UsageLimit::windowLabel('seven_day'))->toBe('недельный лимит')
        ->and(UsageLimit::windowLabel('seven_day_opus'))->toBe('недельный лимит модели')
        ->and(UsageLimit::windowLabel(null))->toBe('лимит');
});
