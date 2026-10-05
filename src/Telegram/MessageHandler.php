<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Illuminate\Support\Facades\Log;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Settings;
use Throwable;

/**
 * The first look at a message the bot received (on the queue, see HandleTelegramUpdate): pairs the developer's
 * chat (/start <code>), ignores other chats, answers /start and /help, and hands everything else to the
 * assistant (AssistantLauncher → Responder).
 */
final readonly class MessageHandler
{
    public const string HELP = "Я — менеджер проекта в Telegram: присылаю вопросы агентов и короткие отчёты о сделанной работе.\n\n"
        ."• Чтобы ответить на вопросы по задаче, ответьте (reply) на сообщение с вопросами — текстом или голосовым.\n"
        ."• Чтобы поставить новую задачу, опишите идею своими словами: я заведу её в YouTrack, и цикл её спланирует.\n"
        .'• Спросите что угодно о проекте: что в работе, что заблокировано, что сделано за день.';

    public function __construct(
        private BotSettings $settings,
        private Settings $project,
        private Conversation $conversation,
        private Messenger $messenger,
        private AssistantLauncher $launcher,
        private Bot $bot,
    ) {}

    public function receive(IncomingMessage $message): void
    {
        if (! $this->settings->isConfigured()) {
            return;
        }

        if ($this->settings->chatId === null) {
            $this->pair($message);

            return;
        }

        if ($message->chatId !== $this->settings->chatId) {
            Log::info('agentio: a Telegram message from an unknown chat was ignored', ['chat' => $message->chatId]);

            return;
        }

        if (in_array($message->command(), ['start', 'help'], true)) {
            $this->messenger->send(self::HELP, 'help', replyTo: $message->messageId);

            return;
        }

        $key = $this->conversation->putInbox($message);

        try {
            $this->bot->typing($message->chatId);
        } catch (Throwable) {
            // Only a courtesy.
        }

        if (! $this->launcher->launch($key)) {
            $this->conversation->forgetInbox($key);
            $this->messenger->send('⚠️ Не смог запустить ассистента (php artisan agentio:telegram assist): посмотрите telegram-assistant.log в каталоге логов agentio.', 'error', replyTo: $message->messageId);
        }
    }

    /**
     * /start <code> with the code agentio:setup-telegram printed binds the bot to this chat.
     */
    private function pair(IncomingMessage $message): void
    {
        $code = $this->conversation->pairingCode();

        if ($message->command() !== 'start') {
            return;
        }

        if ($code === null || ! hash_equals($code, $message->commandArgument())) {
            $this->messenger->send('Этот бот ещё не привязан к разработчику. Запустите php artisan agentio:setup-telegram и откройте ссылку, которую он покажет.', 'help', chatId: $message->chatId);

            return;
        }

        (new EnvFile($this->project->basePath().'/.env'))->put([BotSettings::CHAT_ID => $message->chatId]);
        $this->conversation->finishPairing();
        // agentio:run sees the new chat in .env and restarts the bot's processes (BotSupervisor).
        $this->messenger->send("✅ Бот привязан к этому чату.\n\n".self::HELP, 'help', chatId: $message->chatId);
    }
}
