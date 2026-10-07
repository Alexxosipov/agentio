<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Obrazmisli\Agentio\Telegram\BotSettings;
use Obrazmisli\Agentio\Telegram\Messenger;
use Obrazmisli\Agentio\Telegram\Reports;

/**
 * A short report of an event of the loop (see Reports::EVENTS): its text is built from YouTrack on the queue.
 */
final class NotifyDeveloper implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** Below the timeout of Horizon's supervisors (60 s) and the retry_after of the connection (90 s) by default. */
    public int $timeout = 50;

    public function __construct(
        public string $event,
        public string $issue,
        public ?string $name = null,
        public ?int $until = null,
        public ?string $window = null,
        public ?string $url = null,
    ) {
        $settings = BotSettings::fromConfig();
        $this->onConnection($settings->queueConnection)->onQueue($settings->queue);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(Reports $reports, Messenger $messenger): void
    {
        $messenger->send(
            $reports->event($this->event, $this->issue, $this->name, $this->until, $this->window, $this->url),
            'report',
            $this->issue === '' ? null : $this->issue,
        );
    }
}
