<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Obrazmisli\Agentio\Runtime\LoopState;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Whether the latest run of an agent session ended at the usage limit of Claude Code (the loop asks it about every
 * session that ended unfinished). At the limit it prints "<resume time> <limit>" — when the loop may start sessions
 * again (a Unix time: a minute after the reset, or AGENTIO_LIMIT_RETRY seconds from now when the reset is unknown)
 * and the limit hit (five_hour, seven_day, ... or "-") — and exits 0; otherwise it prints nothing and exits 1.
 */
#[AsCommand(name: 'agentio:limit')]
final class LimitCommand extends Command
{
    /** Seconds after the reset before the loop resumes: the clocks of the machine and of the API differ. */
    public const int MARGIN = 60;

    /**
     * @var string
     */
    protected $signature = 'agentio:limit
        {session : EPIC-ID or plan-IDEA-ID}';

    /**
     * @var string
     */
    protected $description = 'Tell whether an agent session of the loop ended at the usage limit of Claude Code and when to resume';

    public function handle(LoopState $loop): int
    {
        $now = CarbonImmutable::now();
        $limit = $loop->sessionLog((string) $this->argument('session'))->usageLimit(now: $now);

        if ($limit === null) {
            return self::FAILURE;
        }

        $resumesAt = $limit->resetsAt !== null && $limit->resetsAt->greaterThan($now)
            ? $limit->resetsAt->addSeconds(self::MARGIN)
            : $now->addSeconds(max(self::MARGIN, (int) config('agentio.limit_retry', 900)));

        $this->line($resumesAt->getTimestamp().' '.($limit->window ?? '-'));

        return self::SUCCESS;
    }
}
