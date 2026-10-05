<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Queue;

/**
 * The state of Laravel Horizon in the project (php artisan horizon:status).
 */
enum HorizonStatus: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Inactive = 'inactive';
    case Unreachable = 'redis unreachable';
    case Missing = 'not installed';

    public function isRunning(): bool
    {
        return $this === self::Running || $this === self::Paused;
    }
}
