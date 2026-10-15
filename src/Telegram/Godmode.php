<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Runtime\SessionSettings;
use Obrazmisli\Agentio\Settings;
use RuntimeException;

/**
 * Godmode of the bot: the developer works on the project from the chat, without opening Claude Code. Every
 * message is a task for a headless Claude Code session in the main checkout with full access — neither the
 * permission rules of agentio nor its guard apply (--dangerously-skip-permissions) —, and the messages of one
 * godmode continue the same session (--resume). Off unless AGENTIO_TELEGRAM_GODMODE is set (BotSettings::$godmode):
 * whoever can write to the paired chat can then run any command on the machine.
 */
final readonly class Godmode
{
    public function __construct(
        private Settings $settings,
        private SessionSettings $sessions,
        private BotSettings $bot,
    ) {}

    /**
     * What the session is told on top of its task (appended to the system prompt): where it works, that nobody
     * answers a permission prompt, and how to reply to a chat.
     */
    public function instructions(): string
    {
        $project = $this->settings->project();
        $base = $this->settings->baseBranch();
        $production = $this->settings->productionBranch();

        return implode("\n", [
            "Ты работаешь над проектом {$project} по сообщениям разработчика из Telegram-бота agentio (режим godmode), в главном каталоге проекта. Ветка разработки — {$base}, продакшен — {$production}.",
            'У тебя полный доступ, а подтверждений никто не даст: доведи задачу до конца сам и проверь результат так, как принято в проекте (CLAUDE.md: тесты, линтеры).',
            '- Ответ читают в Telegram с телефона: на русском, коротко (до 12 строк), обычным текстом; из Markdown — только **жирный**, `код` и списки «- », без таблиц и длинных листингов. Что сделано, что проверено, что осталось или ждёт решения разработчика.',
            "- Необратимое — удаление данных и файлов, которых нет в git, force push, переписывание истории, слияние в {$production}, выкладка, траты денег — делай, только когда разработчик прямо попросил именно это; иначе опиши план и спроси.",
            "- В проекте работает автономный цикл agentio: эпики идут в отдельных worktree и ветках задач ({$project}-N) и ведутся в YouTrack (MCP-сервер youtrack, php artisan agentio:status). Их worktree, ветки и захваты не трогай, если разработчик об этом не просил.",
            '- Значения секретов (.env, токены) в ответ не выводи.',
        ]);
    }

    /**
     * Run a task and return the final text of the session with its id, which the next task continues.
     *
     * @param  string|null  $session  The session of the previous task of this godmode, null for a new one
     * @return array{reply: string, session: string|null}
     *
     * @throws RuntimeException When Claude Code is missing, fails or does not finish in time
     */
    public function run(string $task, ?string $session = null): array
    {
        $command = [
            $this->settings->claudeBinary(), '-p', $task,
            '--output-format', 'json',
            '--dangerously-skip-permissions',
            '--append-system-prompt', $this->instructions(),
            '--strict-mcp-config', '--mcp-config', ...$this->sessions->mcpConfigs(),
        ];

        if ($session !== null) {
            array_push($command, '--resume', $session);
        }

        $model = $this->settings->claudeModel();

        if ($model !== null) {
            array_push($command, '--model', $model);
        }

        // Like the sessions of the loop, without the variables of .env: the tests the session runs must not
        // inherit APP_ENV and DB_* of the developer's application.
        try {
            $result = Process::path($this->settings->basePath())
                ->timeout($this->bot->godmodeTimeout)
                ->env([
                    ...(new EnvFile($this->settings->basePath().'/.env'))->unloaded(),
                    ...array_filter([
                        'YOUTRACK_URL' => Settings::string('agentio.youtrack.url'),
                        'YOUTRACK_TOKEN' => Settings::string('agentio.youtrack.token'),
                    ], fn (?string $value): bool => $value !== null),
                ])
                ->run($command);
        } catch (ProcessTimedOutException) {
            throw new RuntimeException("Claude Code did not finish in {$this->bot->godmodeTimeout} seconds (AGENTIO_TELEGRAM_GODMODE_TIMEOUT) and was stopped; what it changed so far stays in the project.");
        }

        $output = json_decode($result->output(), true);

        if ($result->failed() || ! is_array($output) || ($output['is_error'] ?? false) === true || ! is_string($output['result'] ?? null)) {
            $reason = is_array($output) && is_string($output['result'] ?? null) ? $output['result'] : trim($result->errorOutput() ?: $result->output());

            // The session of the previous task is gone (Claude Code cleaned it up): start a new one.
            if ($session !== null && stripos($reason, 'No conversation found') !== false) {
                return $this->run($task);
            }

            throw new RuntimeException('Claude Code failed (exit '.($result->exitCode() ?? '?').'): '.TelegramText::limit($reason, 500));
        }

        return ['reply' => trim($output['result']), 'session' => is_string($output['session_id'] ?? null) ? $output['session_id'] : $session];
    }
}
