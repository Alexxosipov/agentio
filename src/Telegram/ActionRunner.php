<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Obrazmisli\Agentio\Process\AgentCommentKind;
use Obrazmisli\Agentio\Process\AgentComments;
use Obrazmisli\Agentio\Review\EpicAcceptance;
use Obrazmisli\Agentio\Review\Release;
use Obrazmisli\Agentio\Review\ReviewException;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\Tag;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * Makes the changes the assistant decided on and reports each one in a line of Russian for the developer. YouTrack
 * changes go through the YouTrack MCP server: an answer to the questions of an issue is a comment that does not
 * start with [AGENT:, so the resumed agent reads it as the human's answer (see the «Вопросы к человеку» section of
 * agentio-youtrack-workflow); with resume, an issue in Blocked goes back to the Stage its [AGENT:BLOCKED] names.
 * Merges go through pull requests on GitHub and never create a YouTrack issue: an epic is accepted the way the
 * dashboard does it, a release is merged only after the developer confirmed the question the bot asked.
 */
final readonly class ActionRunner
{
    public function __construct(
        private Tools $tools,
        private Settings $settings,
        private EpicAcceptance $acceptance,
        private Release $release,
        private Conversation $conversation,
    ) {}

    /**
     * @return array{lines: list<string>, issues: list<string>, kind: string|null} What was done (or failed), the
     *                                                                             issues touched, and the kind of the reply
     *                                                                             (release when it asks to confirm one)
     */
    public function run(Decision $decision, IncomingMessage $message, string $text): array
    {
        $lines = [];
        $issues = [];
        $kind = null;

        foreach ($decision->actions as $action) {
            try {
                [$line, $issue] = match ($action['type']) {
                    DecisionAction::Answer => $this->answer($action['issue'], $action['comment'] !== '' ? $action['comment'] : $text, $action['resume'], $message, $text),
                    DecisionAction::Comment => $this->comment($action['issue'], $action['comment'] !== '' ? $action['comment'] : $text, $message, $text),
                    DecisionAction::Idea => $this->idea($action['summary'], $action['description'] !== '' ? $action['description'] : $text, $message),
                    DecisionAction::Merge => $this->merge($action['issue']),
                    DecisionAction::Release => $this->release($action['confirm']),
                };
            } catch (YouTrackException $exception) {
                [$line, $issue] = ['⚠️ Не удалось записать в YouTrack'.($action['issue'] === null ? '' : ' ('.$action['issue'].')').': '.TelegramText::limit($exception->getMessage(), 300), $action['issue']];
            }

            if ($action['type'] === DecisionAction::Release && $this->conversation->pendingRelease() !== null) {
                $kind = 'release';
            }

            $lines[] = $line;

            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return ['lines' => $lines, 'issues' => array_values(array_unique($issues)), 'kind' => $kind];
    }

    /**
     * The developer asked to merge an epic: its pull request is merged into the development branch on GitHub (opened
     * first when there is none), the worktree removed, the reviewed stories and the epic moved to Done.
     *
     * @return array{0: string, 1: string|null}
     */
    public function merge(?string $id): array
    {
        if ($id === null) {
            return ['⚠️ Не понял, какой эпик слить: напишите его ID.', null];
        }

        try {
            $result = $this->acceptance->accept($id);
        } catch (ReviewException $exception) {
            return ['⚠️ Не смог слить '.$id.': '.self::reason($exception), $id];
        }

        $request = $result['pullRequest'];
        $label = $request === null ? '' : 'PR #'.$request['number'].' ';
        $lines = [$result['merged']
            ? "✅ Слил {$label}эпика {$id} в {$result['base']} ({$result['commit']})".($request === null ? '.' : ': '.$request['url'])
            : "✅ Эпик {$id} уже в {$result['base']}".($request === null ? '' : " ({$label}{$request['url']})").': прибрал worktree и закрыл задачи.'];

        if ($result['closed'] !== []) {
            $lines[] = 'В Done: '.implode(', ', $result['closed']).'.';
        }

        foreach ($result['warnings'] as $warning) {
            $lines[] = '⚠️ '.$warning;
        }

        return [implode("\n", $lines), $id];
    }

    /**
     * The developer asked for a release: the pull request of the development branch into the production branch is
     * opened (or the open one found) and the developer asked to confirm it. Only a confirmation of that question
     * merges it, and only at the commit the question was about.
     *
     * @return array{0: string, 1: null}
     */
    public function release(bool $confirm): array
    {
        $pending = $this->conversation->pendingRelease();

        if ($confirm && $pending !== null) {
            try {
                $merged = $this->release->merge($pending['number'], $pending['head']);
            } catch (ReviewException $exception) {
                if ($exception->status === Release::MOVED) {
                    return $this->askRelease('⚠️ '.$exception->getMessage());
                }

                return ['⚠️ Релиз не слит: '.self::reason($exception), null];
            }

            $this->conversation->forgetRelease();
            $pullRequest = $merged['pullRequest'];

            return [implode("\n", [
                "🚀 Релиз выпущен: PR #{$pullRequest->number} ({$pullRequest->head} → {$pullRequest->base}) слит ({$merged['commit']}): {$pullRequest->url}",
                ...array_map(fn (string $warning): string => '⚠️ '.$warning, $merged['warnings']),
            ]), null];
        }

        return $this->askRelease($confirm ? 'Подтверждать пока нечего: сначала открою PR релиза.' : null);
    }

    /**
     * @return array{0: string, 1: null}
     */
    private function askRelease(?string $note): array
    {
        try {
            $prepared = $this->release->prepare();
        } catch (ReviewException $exception) {
            $this->conversation->forgetRelease();

            return [trim(($note ?? '')."\n".'⚠️ Не смог подготовить релиз: '.self::reason($exception)), null];
        }

        $pullRequest = $prepared['pullRequest'];
        $this->conversation->askRelease($pullRequest->number, $prepared['head'], $pullRequest->url);

        return [implode("\n", array_filter([
            $note,
            "🚀 PR релиза #{$pullRequest->number} {$pullRequest->head} → {$pullRequest->base}".($prepared['created'] ? ' открыт' : ' уже открыт')." ({$prepared['commits']} коммитов): {$pullRequest->url}",
            $prepared['unpushed'] > 0 ? "⚠️ В локальной {$pullRequest->head} есть коммиты, которых нет на GitHub ({$prepared['unpushed']}): в релиз они не попадут." : null,
            "Слить его в {$pullRequest->base}? Ответьте «да» на это сообщение (reply) — без подтверждения не сливаю.",
        ])), null];
    }

    private static function reason(ReviewException $exception): string
    {
        return TelegramText::limit($exception->getMessage().($exception->details === [] ? '' : ' ('.implode('; ', $exception->details).')'), 500);
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
