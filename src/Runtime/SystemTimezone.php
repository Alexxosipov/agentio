<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

use DateTimeZone;

/**
 * The timezone of the machine: scripts/agent-loop.sh writes local time ("date '+%F %T'"), while
 * Laravel usually runs in UTC, so the loop's timestamps are read in the machine's timezone.
 */
final class SystemTimezone
{
    /**
     * The first valid timezone of: $TZ, /etc/timezone, the /etc/localtime symlink; null when unknown.
     */
    public static function detect(?string $tz = null, string $timezoneFile = '/etc/timezone', string $localtime = '/etc/localtime'): ?string
    {
        $candidates = [$tz ?? (getenv('TZ') ?: null)];

        if (is_file($timezoneFile)) {
            $candidates[] = trim((string) file_get_contents($timezoneFile));
        }

        if (is_link($localtime)) {
            $target = (string) readlink($localtime);
            $candidates[] = preg_match('#zoneinfo/(.+)$#', $target, $match) === 1 ? $match[1] : null;
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && self::isValid(ltrim($candidate, ':'))) {
                return ltrim($candidate, ':');
            }
        }

        return null;
    }

    private static function isValid(string $timezone): bool
    {
        return $timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }
}
