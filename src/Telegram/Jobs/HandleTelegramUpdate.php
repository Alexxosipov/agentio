<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Obrazmisli\Agentio\Telegram\BotSettings;
use Obrazmisli\Agentio\Telegram\IncomingMessage;
use Obrazmisli\Agentio\Telegram\MessageHandler;

/**
 * An update the listener received: handled on the queue, so the listener only reads Telegram.
 */
final class HandleTelegramUpdate implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** Below the timeout of Horizon's supervisors (60 s) and the retry_after of the connection (90 s) by default. */
    public int $timeout = 50;

    /**
     * @param  array<array-key, mixed>  $update
     */
    public function __construct(public array $update)
    {
        $settings = BotSettings::fromConfig();
        $this->onConnection($settings->queueConnection)->onQueue($settings->queue);
    }

    /**
     * An update queued twice (the listener stopped before it confirmed it) is handled once: Telegram's update_id
     * is remembered for a day.
     */
    public function handle(MessageHandler $handler, Repository $cache): void
    {
        $id = $this->update['update_id'] ?? null;

        if (is_int($id) && ! $cache->add('agentio:telegram:update:'.$id, true, 86400)) {
            return;
        }

        $message = IncomingMessage::fromUpdate($this->update);

        if ($message !== null) {
            $handler->receive($message);
        }
    }
}
