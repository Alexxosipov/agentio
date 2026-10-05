<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Obrazmisli\Agentio\Telegram\Bot;
use Obrazmisli\Agentio\Telegram\BotSettings;
use Obrazmisli\Agentio\Telegram\Conversation;
use Obrazmisli\Agentio\Telegram\TelegramException;
use Throwable;

/**
 * Sends a message of the bot and remembers what it is about (Conversation), so a reply is tied to its issue.
 * Flood control and outages are retried; a message Telegram rejects is dropped.
 */
final class SendTelegramMessage implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** Below the timeout of Horizon's supervisors (60 s) and the retry_after of the connection (90 s) by default. */
    public int $timeout = 50;

    /**
     * @param  string  $kind  question, report, answer, help or error
     */
    public function __construct(
        public string $text,
        public string $kind = 'report',
        public ?string $issue = null,
        public ?string $stage = null,
        public ?int $replyTo = null,
        public ?string $chatId = null,
    ) {
        $settings = BotSettings::fromConfig();
        $this->onConnection($settings->queueConnection)->onQueue($settings->queue);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    /**
     * A message Telegram did not take after every try is gone: say so in the log of the application.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('agentio: a Telegram message was not delivered', ['kind' => $this->kind, 'issue' => $this->issue, 'error' => $exception?->getMessage()]);
    }

    public function handle(BotSettings $settings, Bot $bot, Conversation $conversation): void
    {
        $chat = $this->chatId ?? $settings->chatId;

        if (! $settings->isConfigured() || $chat === null) {
            return;
        }

        try {
            $ids = $bot->send($chat, $this->text, $this->replyTo);
        } catch (TelegramException $exception) {
            if ($exception->retryAfter !== null) {
                $this->release($exception->retryAfter + 1);

                return;
            }

            if (! $exception->isTemporary()) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }

        $conversation->remember($ids, $this->kind, $this->issue, $this->stage);

        if (in_array($this->kind, ['answer', 'question', 'report'], true)) {
            $conversation->addHistory('bot', $this->text, $this->issue);
        }
    }
}
