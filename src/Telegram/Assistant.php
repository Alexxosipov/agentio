<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Illuminate\Support\Facades\Process;
use Obrazmisli\Agentio\Runtime\SessionSettings;
use Obrazmisli\Agentio\Settings;
use RuntimeException;

/**
 * The project manager behind the bot: a headless Claude Code session in the main checkout with the
 * agentio-telegram-assistant skill. It reads the code, YouTrack (through its MCP server) and the state of the
 * loop, and answers with a Decision; it changes nothing itself (resources/claude/assistant.json).
 */
final readonly class Assistant
{
    public const string SKILL = 'agentio-telegram-assistant';

    public function __construct(
        private Settings $settings,
        private SessionSettings $sessions,
        private BotSettings $bot,
    ) {}

    /**
     * The prompt of the session: load the skill (it is not user-invocable, so not a slash command), then the
     * message and its context in Russian.
     *
     * @param  array{kind: string, issue: string|null, stage: string|null}|null  $about  What the replied message is about
     * @param  list<array{role: string, text: string, issue: string|null, at: string}>  $history
     */
    public function prompt(IncomingMessage $message, string $text, ?array $about, array $history): string
    {
        $lines = ['Загрузи скилл '.self::SKILL.' (инструмент Skill) и примени его к сообщению разработчика ниже.', '', '## Сообщение разработчика', ''];
        $lines[] = 'Проект YouTrack: '.$this->settings->project().'. Время: '.date('Y-m-d H:i').'.';
        $lines[] = 'Вид: '.($message->isVoice() ? 'голосовое (ниже — расшифровка, в ней возможны ошибки распознавания)' : 'текст').'.';

        if ($message->replyToId !== null) {
            $lines[] = 'Это ответ (reply) на '.($message->replyToBot ? 'сообщение бота' : 'сообщение').'.';

            if ($about !== null) {
                $lines[] = sprintf('Сообщение, на которое отвечают: вид «%s»%s%s.', $about['kind'], $about['issue'] === null ? '' : ', задача '.$about['issue'], $about['stage'] === null ? '' : ', после ответа вернуть Stage '.$about['stage']);
            }

            if ($message->replyToText !== null) {
                $lines[] = '';
                $lines[] = 'Текст сообщения, на которое отвечают:';
                $lines[] = self::quote(TelegramText::limit($message->replyToText, 3000));
            }
        } else {
            $lines[] = 'Это не reply: определи по смыслу и по истории, к чему оно относится.';
        }

        $lines[] = '';
        $lines[] = 'Текст сообщения:';
        $lines[] = self::quote($text);

        if ($history !== []) {
            $lines[] = '';
            $lines[] = '## Недавняя переписка (старые сверху)';
            $lines[] = '';

            foreach ($history as $entry) {
                $lines[] = sprintf('- [%s] %s%s: %s', $entry['at'], $entry['role'] === 'bot' ? 'бот' : 'разработчик', $entry['issue'] === null ? '' : ' ('.$entry['issue'].')', str_replace("\n", ' ', TelegramText::limit($entry['text'], 500)));
            }
        }

        $lines[] = '';
        $lines[] = 'Ответь строго JSON-объектом по контракту скилла.';

        return implode("\n", $lines);
    }

    /**
     * Run the session and read its decision.
     *
     * @throws RuntimeException When Claude Code is missing, fails or times out
     */
    public function decide(string $prompt): Decision
    {
        $command = [
            $this->settings->claudeBinary(), '-p', $prompt,
            '--output-format', 'json',
            '--permission-mode', 'dontAsk',
            '--strict-mcp-config', '--mcp-config', ...$this->sessions->mcpConfigs(),
            '--settings', $this->sessions->assistantJson(),
        ];

        $model = $this->settings->claudeModel();

        if ($model !== null) {
            array_push($command, '--model', $model);
        }

        $result = Process::path($this->settings->basePath())
            ->timeout($this->bot->assistantTimeout)
            ->env(array_filter([
                'YOUTRACK_URL' => Settings::string('agentio.youtrack.url'),
                'YOUTRACK_TOKEN' => Settings::string('agentio.youtrack.token'),
            ], fn (?string $value): bool => $value !== null))
            ->run($command);

        $output = json_decode($result->output(), true);

        if ($result->failed() || ! is_array($output) || ($output['is_error'] ?? false) === true || ! is_string($output['result'] ?? null)) {
            $reason = is_array($output) && is_string($output['result'] ?? null) ? $output['result'] : trim($result->errorOutput() ?: $result->output());

            throw new RuntimeException('Claude Code failed (exit '.($result->exitCode() ?? '?').'): '.TelegramText::limit($reason, 500));
        }

        return Decision::fromText($output['result']);
    }

    private static function quote(string $text): string
    {
        return implode("\n", array_map(fn (string $line): string => '> '.$line, explode("\n", trim($text))));
    }
}
