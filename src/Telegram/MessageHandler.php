<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Illuminate\Support\Facades\Log;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Settings;
use Throwable;

/**
 * The first look at a message the bot received (on the queue, see HandleTelegramUpdate): pairs the developer's
 * chat (/start <code>), ignores other chats, answers /start and /help, switches godmode on and off (/godmode,
 * /exit) and hands everything else to the assistant — or, in godmode, to its full-access session
 * (AssistantLauncher → Responder).
 */
final readonly class MessageHandler
{
    public const string HELP = "Я — менеджер проекта в Telegram: присылаю вопросы агентов и короткие отчёты о сделанной работе.\n\n"
        ."• Чтобы ответить на вопросы по задаче, нажмите «Принять рекомендации» под ними или ответьте (reply) на сообщение с вопросами — текстом или голосовым.\n"
        ."• Чтобы поставить новую задачу, опишите идею своими словами: я заведу её в YouTrack, и цикл её спланирует.\n"
        ."• Идею на потом — «идея на потом: …»: агенты только проанализируют её и сложат в статью «Идеи» базы знаний, в разработку она пойдёт после вашего «бери в работу TP-5».\n"
        ."• Чтобы приостановить эпик, напишите «останови TP-12» (агенты доделают текущую волну задач) или «останови TP-12 сейчас»; продолжить — «продолжи TP-12». Скажу, какие эпики ждут его.\n"
        ."• Чтобы принять эпик, напишите «смержи TP-12» (или ответьте «мержи» на отчёт о готовом эпике): солью его pull request в ветку разработки и закрою эпик — без новых задач в YouTrack.\n"
        ."• Чтобы выпустить релиз, напишите «вмержи dev в main»: открою pull request релиза, пришлю ссылку и солью только после вашего «да».\n"
        ."• Спросите что угодно о проекте: что в работе, что заблокировано, что сделано за день.\n"
        .'• Чтобы работать с проектом прямо отсюда — править код, запускать команды, ставить пакеты, — включите godmode: /godmode (или сразу /godmode <задача>); выйти — /exit. Его разрешает AGENTIO_TELEGRAM_GODMODE=true в .env проекта.';

    /** The arguments of /godmode that switch it off, like /exit. */
    private const array OFF = ['off', 'exit', 'stop', 'выкл', 'выход', 'стоп'];

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

        if (in_array($message->command(), ['godmode', 'exit'], true)) {
            $message = $this->godmode($message);

            if ($message === null) {
                return;
            }
        } elseif ($this->inGodmode($message)) {
            $message = $message->forGodmode($message->text);
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

            return;
        }

        if ($message->godmode) {
            $this->messenger->send('⚡ Принял задачу, отвечу, когда закончу.', 'help', replyTo: $message->messageId);
        }
    }

    /**
     * /godmode [task] switches godmode on (a new session of Claude Code) and /exit, or /godmode off, switches it
     * off. Returns the task to run right away, or null when the command is answered.
     */
    private function godmode(IncomingMessage $message): ?IncomingMessage
    {
        $task = $message->commandArgument();

        if ($message->command() === 'exit' || in_array(mb_strtolower($task), self::OFF, true)) {
            $on = $this->conversation->inGodmode($this->settings->godmodeIdle);
            $this->conversation->stopGodmode();
            $this->messenger->send($on ? '⚡ Godmode выключен: дальше отвечает ассистент.' : 'Godmode не включён.', 'help', replyTo: $message->messageId);

            return null;
        }

        if (! $this->settings->godmode) {
            $this->messenger->send("⚡ Godmode выключен в настройках проекта. Он даёт этому чату полный доступ к проекту и к машине, на которой работает agentio: любые команды, правки кода, git.\n\nЧтобы разрешить его, добавьте в .env проекта AGENTIO_TELEGRAM_GODMODE=true — бот перезапустится сам — и повторите /godmode.", 'help', replyTo: $message->messageId);

            return null;
        }

        $this->conversation->startGodmode();

        if ($task !== '') {
            return $message->forGodmode($task);
        }

        $this->messenger->send(sprintf(
            "⚡ Godmode включён. Каждое следующее сообщение, текстом или голосом, — задача для Claude Code с полным доступом к проекту: код, команды, пакеты, git. Сообщения продолжают одну сессию, так что на сделанное можно ссылаться.\n\nОтветы на вопросы агентов (reply на сообщение с вопросами) по-прежнему уходят в YouTrack.\n\n/exit — выключить; сам выключится через %d мин без сообщений.",
            (int) ceil($this->settings->godmodeIdle / 60),
        ), 'help', replyTo: $message->messageId);

        return null;
    }

    /**
     * Whether the message is a task of godmode. Godmode left without a message for too long goes off, and the
     * message is for the assistant; so is, always, a reply to the questions of the agents.
     */
    private function inGodmode(IncomingMessage $message): bool
    {
        if (! $this->settings->godmode) {
            return false;
        }

        if ($this->conversation->godmodeExpired($this->settings->godmodeIdle)) {
            $this->conversation->stopGodmode();
            $this->messenger->send('⚡ Godmode выключился: в нём давно не было сообщений. Это сообщение получил ассистент; включить снова — /godmode.', 'help', replyTo: $message->messageId);

            return false;
        }

        if (! $this->conversation->inGodmode($this->settings->godmodeIdle)) {
            return false;
        }

        if ($message->replyToId !== null && ($this->conversation->about($message->replyToId)['kind'] ?? null) === 'question') {
            return false;
        }

        $this->conversation->continueGodmode();

        return true;
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
