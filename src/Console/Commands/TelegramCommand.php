<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Obrazmisli\Agentio\Queue\Horizon;
use Obrazmisli\Agentio\Telegram\BotSettings;
use Obrazmisli\Agentio\Telegram\Conversation;
use Obrazmisli\Agentio\Telegram\Jobs\NotifyDeveloper;
use Obrazmisli\Agentio\Telegram\Listener;
use Obrazmisli\Agentio\Telegram\Messenger;
use Obrazmisli\Agentio\Telegram\Reports;
use Obrazmisli\Agentio\Telegram\Responder;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * The processes of the developer's Telegram bot: listen (started by agentio:run next to the loop), notify (called
 * by the loop on its events; never fails), assist (started for every message of the developer), send and status.
 */
#[AsCommand(name: 'agentio:telegram')]
final class TelegramCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agentio:telegram
        {action=status : listen, notify, assist, send or status}
        {argument? : notify: the event (planned, review, merged, blocked); assist: the message key; send: the text}
        {id? : notify: the issue id}
        {--name= : notify blocked: the name of the session (its log)}
        {--passes= : listen: stop after this many polls}
        {--poll-timeout=25 : listen: seconds a poll waits for updates}';

    /**
     * @var string
     */
    protected $description = 'The developer\'s Telegram bot of agentio: listen for messages, report events of the loop, answer messages';

    private bool $stopping = false;

    public function handle(BotSettings $settings): int
    {
        return match ((string) $this->argument('action')) {
            'listen' => $this->listen($settings),
            'notify' => $this->notify($settings),
            'assist' => $this->assist(),
            'send' => $this->send(),
            'status' => $this->status($settings),
            default => $this->invalid('Unknown action: use listen, notify, assist, send or status.'),
        };
    }

    private function listen(BotSettings $settings): int
    {
        if (! $settings->isConfigured()) {
            return $this->invalid('AGENTIO_TELEGRAM_BOT_TOKEN is not set: run php artisan agentio:setup-telegram.');
        }

        // SIGINT and SIGTERM (numbers, so that the command also loads without the pcntl extension).
        $this->trap([2, 15], function (): void {
            $this->stopping = true;
        });

        $passes = $this->option('passes');
        $this->line('['.date('Y-m-d H:i:s').'] listening for Telegram updates');

        $this->laravel->make(Listener::class)->run(
            fn (): bool => $this->stopping,
            fn (string $message) => $this->line('['.date('Y-m-d H:i:s').'] '.$message),
            is_numeric($passes) ? (int) $passes : null,
            max(0, (int) $this->option('poll-timeout')),
        );

        $this->line('['.date('Y-m-d H:i:s').'] stopped');

        return self::SUCCESS;
    }

    /**
     * Queue the report of an event of the loop. Exits 0 whatever happens: the loop must never fail because of
     * the bot.
     */
    private function notify(BotSettings $settings): int
    {
        $event = (string) $this->argument('argument');
        $id = (string) $this->argument('id');

        if (! $settings->isPaired() || ! in_array($event, Reports::EVENTS, true) || $id === '') {
            return self::SUCCESS;
        }

        try {
            $this->laravel->make(Dispatcher::class)->dispatch(new NotifyDeveloper($event, $id, $this->stringOption('name')));
        } catch (Throwable $exception) {
            $this->components->warn('The Telegram report was not queued: '.$exception->getMessage());
        }

        return self::SUCCESS;
    }

    private function assist(): int
    {
        $key = (string) $this->argument('argument');

        if (! $this->laravel->make(Responder::class)->answer($key)) {
            return $this->invalid("No message {$key} waits for the assistant.");
        }

        return self::SUCCESS;
    }

    private function send(): int
    {
        $text = trim((string) $this->argument('argument'));

        if ($text === '') {
            return $this->invalid('Pass the text: php artisan agentio:telegram send "…"');
        }

        if (! $this->laravel->make(Messenger::class)->send($text)) {
            return $this->invalid('Not queued: the bot is not set up or not paired (php artisan agentio:setup-telegram).');
        }

        $this->components->info('Queued.');

        return self::SUCCESS;
    }

    private function status(BotSettings $settings): int
    {
        $horizon = $this->laravel->make(Horizon::class);
        $conversation = $this->laravel->make(Conversation::class);

        $this->components->twoColumnDetail('Token (AGENTIO_TELEGRAM_BOT_TOKEN)', $settings->isConfigured() ? '<fg=green>set</>' : '<fg=yellow>not set</>');
        $this->components->twoColumnDetail('Chat (AGENTIO_TELEGRAM_CHAT_ID)', $settings->chatId ?? '<fg=yellow>not paired</>');
        $this->components->twoColumnDetail('Voice messages (AGENTIO_TRANSCRIPTION_DRIVER)', (string) (config('agentio.telegram.transcription.driver') ?? 'none'));
        $this->components->twoColumnDetail('Queue', $settings->queueConnection.' / '.$settings->queue);
        $this->components->twoColumnDetail('Horizon', $horizon->status()->value);
        $this->components->twoColumnDetail('State', $conversation->directory());

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        $this->components->error($message);

        return self::INVALID;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
