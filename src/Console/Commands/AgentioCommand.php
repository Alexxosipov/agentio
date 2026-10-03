<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;

class AgentioCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'agentio:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package agentio.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('Agentio placeholder command executed.');

        return self::SUCCESS;
    }
}
