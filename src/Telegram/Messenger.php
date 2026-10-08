<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Illuminate\Contracts\Bus\Dispatcher;
use Obrazmisli\Agentio\Telegram\Jobs\SendTelegramMessage;
use Throwable;

/**
 * Everything the bot says goes through the queue (BotSettings::$queue of $queueConnection, worked by Horizon):
 * the caller never waits for Telegram and never fails because of it.
 */
final readonly class Messenger
{
    public function __construct(
        private BotSettings $settings,
        private Dispatcher $dispatcher,
    ) {}

    /**
     * Queue a message to the developer (or to $chatId); returns false when the bot is not set up or the queue
     * refused it (reported, never thrown).
     *
     * @param  string  $kind  What it is: question, report, answer, help, error
     * @param  array<string, mixed>|null  $markup  Buttons under the message or a request for a reply (Bot::send())
     */
    public function send(string $text, string $kind = 'report', ?string $issue = null, ?string $stage = null, ?int $replyTo = null, ?string $chatId = null, ?array $markup = null): bool
    {
        if (! $this->settings->isConfigured() || ($chatId ?? $this->settings->chatId) === null || trim($text) === '') {
            return false;
        }

        try {
            $this->dispatcher->dispatch(new SendTelegramMessage($text, $kind, $issue, $stage, $replyTo, $chatId, $markup));
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }
}
