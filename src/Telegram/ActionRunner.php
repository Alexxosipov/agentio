<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Obrazmisli\Agentio\Process\AgentCommentKind;
use Obrazmisli\Agentio\Process\AgentComments;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\Tag;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * Makes the YouTrack changes the assistant decided on, through the YouTrack MCP server, and reports each one in
 * a line of Russian for the developer. An answer to the questions of an issue is a comment that does not start
 * with [AGENT:, so the resumed agent reads it as the human's answer (see the «Вопросы к человеку» section of
 * agentio-youtrack-workflow); with resume, an issue in Blocked goes back to the Stage its [AGENT:BLOCKED] names.
 */
final readonly class ActionRunner
{
    public function __construct(
        private Tools $tools,
        private Settings $settings,
    ) {}

    /**
     * @return array{lines: list<string>, issues: list<string>} What was done (or failed), and the issues touched
     */
    public function run(Decision $decision, IncomingMessage $message, string $text): array
    {
        $lines = [];
        $issues = [];

        foreach ($decision->actions as $action) {
            try {
                [$line, $issue] = match ($action['type']) {
                    DecisionAction::Answer => $this->answer($action['issue'], $action['comment'] !== '' ? $action['comment'] : $text, $action['resume'], $message, $text),
                    DecisionAction::Comment => $this->comment($action['issue'], $action['comment'] !== '' ? $action['comment'] : $text, $message, $text),
                    DecisionAction::Idea => $this->idea($action['summary'], $action['description'] !== '' ? $action['description'] : $text, $message),
                };
            } catch (YouTrackException $exception) {
                [$line, $issue] = ['⚠️ Не удалось записать в YouTrack'.($action['issue'] === null ? '' : ' ('.$action['issue'].')').': '.TelegramText::limit($exception->getMessage(), 300), $action['issue']];
            }

            $lines[] = $line;

            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return ['lines' => $lines, 'issues' => array_values(array_unique($issues))];
    }

    /**
     * The developer's answer to the questions of an issue.
     *
     * @return array{0: string, 1: string|null}
     *
     * @throws YouTrackException
     */
    public function answer(?string $id, string $answer, bool $resume, IncomingMessage $message, string $original): array
    {
        $issue = $id === null ? null : $this->tools->issueWithLinks($id);

        if ($issue === null) {
            return ['⚠️ Не нашёл задачу '.($id ?? '(не указана)').': ответ не записан.', null];
        }

        $this->tools->addComment($issue->id, self::commentText('Ответ разработчика', $answer, $message, $original));

        if (! $resume) {
            return ["📝 Записал ответ комментарием в {$issue->id}; Stage не менял — задача ждёт остальных ответов.", $issue->id];
        }

        if (! $issue->hasState(State::Blocked)) {
            return ["📝 Записал ответ комментарием в {$issue->id}; задача не в Blocked (сейчас ".($issue->state() ?? '—').'), Stage не менял.', $issue->id];
        }

        $blocked = AgentComments::fromComments($this->tools->comments($issue->id))->last(AgentCommentKind::Blocked);
        $stage = ReturnStage::of($issue, $blocked?->text);
        $this->tools->updateFields($issue->id, [State::FIELD => $stage]);

        return ["✅ Ответ записан в {$issue->id}, задача возвращена в {$stage} — агенты продолжат работу.", $issue->id];
    }

    /**
     * A remark of the developer for an issue: a comment, nothing else.
     *
     * @return array{0: string, 1: string|null}
     *
     * @throws YouTrackException
     */
    public function comment(?string $id, string $comment, IncomingMessage $message, string $original): array
    {
        $issue = $id === null ? null : $this->tools->issueWithLinks($id);

        if ($issue === null) {
            return ['⚠️ Не нашёл задачу '.($id ?? '(не указана)').': комментарий не записан.', null];
        }

        $this->tools->addComment($issue->id, self::commentText('Комментарий разработчика', $comment, $message, $original));

        return ["📝 Добавил комментарий в {$issue->id}.", $issue->id];
    }

    /**
     * A new idea for the planning loop (Type Idea, Stage Backlog, tag idea).
     *
     * @return array{0: string, 1: string|null}
     *
     * @throws YouTrackException
     */
    public function idea(string $summary, string $description, IncomingMessage $message): array
    {
        $summary = trim((string) preg_replace('/^\[(IDEA|ИДЕЯ)\]\s*/iu', '', $summary));
        $summary = $summary === '' ? TelegramText::limit(str_replace("\n", ' ', $description), 80) : TelegramText::limit($summary, 120);

        $id = $this->tools->createIssue(
            $this->settings->project(),
            IssueType::Idea->prefix().' '.$summary,
            $description."\n\n---\n_Идея поставлена разработчиком в Telegram".($message->isVoice() ? ' (голосовым сообщением)' : '').' '.date('Y-m-d H:i').'._',
            null,
            [IssueType::FIELD => IssueType::Idea->value, State::FIELD => State::Backlog->value],
        );
        $this->tools->addTag($id, Tag::Idea->value);

        return ["💡 Создал идею {$id} «{$summary}» — цикл спланирует её.", $id];
    }

    private static function commentText(string $title, string $text, IncomingMessage $message, string $original): string
    {
        $comment = $title.' (Telegram'.($message->isVoice() ? ', голосовое сообщение' : '').'):'."\n\n".trim($text);

        if (trim($original) !== '' && trim($original) !== trim($text)) {
            $comment .= "\n\n".'Исходное сообщение'.($message->isVoice() ? ' (расшифровка)' : '').":\n".implode("\n", array_map(fn (string $line): string => '> '.$line, explode("\n", trim($original))));
        }

        return $comment;
    }
}
