<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\Telegram\Jobs\HandleTelegramUpdate;
use Obrazmisli\Agentio\YouTrack\Comment;
use Throwable;

/**
 * The bot's process next to the loop (php artisan agentio:telegram listen, started by agentio:run): reads the
 * updates with getUpdates (long polling: messages and the presses of buttons) and queues each one, and every watch
 * interval sends the new questions of the agents with their buttons. An update is confirmed (the offset moves past it) only after it was queued.
 */
final readonly class Listener
{
    public function __construct(
        private Bot $bot,
        private BotSettings $settings,
        private Conversation $conversation,
        private Dispatcher $dispatcher,
        private QuestionWatcher $watcher,
        private Messenger $messenger,
    ) {}

    /**
     * @param  Closure(): bool  $stopping  Whether to stop (checked between polls)
     * @param  Closure(string): void  $log
     * @param  int|null  $passes  How many polls (null: until $stopping)
     */
    public function run(Closure $stopping, Closure $log, ?int $passes = null, int $pollTimeout = 25): void
    {
        $watched = 0;

        while (! $stopping() && ($passes === null || $passes-- > 0)) {
            if (time() - $watched >= $this->settings->watchInterval) {
                $watched = time();
                $this->watch($log);
            }

            try {
                $updates = $this->bot->updates($this->conversation->offset(), $pollTimeout);
            } catch (TelegramException $exception) {
                $log('getUpdates: '.$exception->getMessage());
                Sleep::for(match (true) {
                    $exception->isConflict() => 15,
                    $exception->isUnauthorized() => 60,
                    default => $exception->retryAfter ?? 5,
                })->seconds();

                continue;
            }

            foreach ($updates as $update) {
                if (! is_int($update['update_id'] ?? null)) {
                    continue;
                }

                try {
                    $this->dispatcher->dispatch(new HandleTelegramUpdate($update));
                } catch (Throwable $exception) {
                    $log('cannot queue update '.$update['update_id'].': '.$exception->getMessage());
                    Sleep::for(5)->seconds();

                    break;
                }

                $this->conversation->setOffset($update['update_id'] + 1);
            }
        }
    }

    /**
     * Send the questions the agents asked since the last look.
     *
     * @param  Closure(string): void  $log
     */
    private function watch(Closure $log): void
    {
        if (! $this->settings->isPaired()) {
            return;
        }

        try {
            $sent = $this->watcher->poll(fn (Comment $comment, string $text): bool => $this->messenger->send($text, 'question', $comment->issueId, ReturnStage::named($comment->text), markup: Reports::questionButtons($comment->issueId)));
        } catch (Throwable $exception) {
            $log('watching the questions: '.$exception->getMessage());

            return;
        }

        if ($sent > 0) {
            $log("sent {$sent} question(s) of the agents");
        }
    }
}
