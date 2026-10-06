<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Testing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Testing\ParallelTesting;

/**
 * Keeps the test runs of the agents apart. Several epics run at once and the developers of an epic run tests in
 * parallel, all against one database server (the "testing" database of phpunit.xml): every run would recreate the
 * tables under the others. In the sessions of the loop (AGENTIO_ISOLATED_TESTS=1) every test process takes a free
 * slot of this machine (a lock held until it exits) and runs as a parallel-testing process of Laravel with the
 * token "agentio_<slot>": Laravel creates and migrates its own database (testing_test_agentio_<slot>) and keeps
 * its cache and compiled views apart. An in-memory SQLite database needs nothing and is left as it is.
 */
final class IsolatedTests
{
    public const string ENV = 'AGENTIO_ISOLATED_TESTS';

    public const int SLOTS = 64;

    /** @var resource|null The lock of the slot, held until the process exits. */
    private static $lock = null;

    private static ?int $slot = null;

    public static function enabled(): bool
    {
        $value = $_SERVER[self::ENV] ?? $_ENV[self::ENV] ?? getenv(self::ENV);

        return in_array($value, ['1', 'true', 1, true], true);
    }

    /**
     * Run the tests of this process as a parallel-testing process of Laravel with the token of its slot.
     */
    public static function register(Application $app): void
    {
        if (! $app->runningUnitTests() || ! self::enabled() || ! $app->bound(ParallelTesting::class)) {
            return;
        }

        $_SERVER['LARAVEL_PARALLEL_TESTING'] = 1;
        $app->make(ParallelTesting::class)->resolveTokenUsing(fn (): string => self::token());
    }

    /**
     * The token of the slot this process holds ("agentio_<slot>"), claimed on the first call.
     */
    public static function token(?string $directory = null): string
    {
        self::$slot ??= self::claim($directory ?? sys_get_temp_dir());

        return 'agentio_'.self::$slot;
    }

    /**
     * Take the first free slot: its lock file stays locked by this process. When every slot is taken, wait for
     * the one of this process id.
     */
    private static function claim(string $directory): int
    {
        $directory = rtrim($directory, '/').'/agentio-tests';

        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        for ($slot = 1; $slot <= self::SLOTS; $slot++) {
            if (self::lock($directory, $slot, wait: false)) {
                return $slot;
            }
        }

        $slot = getmypid() % self::SLOTS + 1;
        self::lock($directory, $slot, wait: true);

        return $slot;
    }

    private static function lock(string $directory, int $slot, bool $wait): bool
    {
        $handle = @fopen($directory.'/slot-'.$slot.'.lock', 'c');

        if ($handle === false) {
            return false;
        }

        if (! flock($handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        self::$lock = $handle;

        return true;
    }

    /**
     * Forget the slot and release its lock (for the tests of agentio).
     */
    public static function release(): void
    {
        if (self::$lock !== null) {
            flock(self::$lock, LOCK_UN);
            fclose(self::$lock);
        }

        [self::$lock, self::$slot] = [null, null];
    }
}
