<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Runtime\SystemTimezone;

it('prefers an explicit valid timezone', function () {
    expect(SystemTimezone::detect('Europe/Moscow', '/nonexistent', '/nonexistent'))->toBe('Europe/Moscow')
        ->and(SystemTimezone::detect(':Asia/Tokyo', '/nonexistent', '/nonexistent'))->toBe('Asia/Tokyo');
});

it('falls back to /etc/timezone and the /etc/localtime link', function () {
    $directory = temporaryDirectory();
    file_put_contents($directory.'/timezone', "Asia/Makassar\n");
    symlink('/usr/share/zoneinfo/America/New_York', $directory.'/localtime');

    expect(SystemTimezone::detect('Not/AZone', $directory.'/timezone', $directory.'/localtime'))->toBe('Asia/Makassar')
        ->and(SystemTimezone::detect('', $directory.'/missing', $directory.'/localtime'))->toBe('America/New_York');
});

it('returns null when nothing names a valid timezone', function () {
    $directory = temporaryDirectory();
    file_put_contents($directory.'/timezone', 'garbage');
    symlink('/somewhere/else', $directory.'/localtime');

    expect(SystemTimezone::detect('', $directory.'/timezone', $directory.'/localtime'))->toBeNull();
});
