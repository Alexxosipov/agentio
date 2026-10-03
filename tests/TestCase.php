<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Tests;

use Obrazmisli\Agentio\AgentioServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AgentioServiceProvider::class,
        ];
    }
}
