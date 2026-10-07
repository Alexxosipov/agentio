<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Carbon\CarbonImmutable;
use Obrazmisli\Agentio\Process\AgentCommentKind;
use Obrazmisli\Agentio\Process\AgentComments;
use Obrazmisli\Agentio\Runtime\SystemTimezone;
use Obrazmisli\Agentio\Runtime\UsageLimit;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\YouTrack\Comment;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;
use Throwable;

/**
 * The messages of the bot, in Russian and short, like a project manager reporting to the developer: the questions
 * of the agents and the events of the loop (an idea planned, an epic ready for review or merged, a session that
 * gave up, the loop paused at the usage limit of Claude Code and resumed after it).
 */
final readonly class Reports
{
    /** The events of the loop the bot reports (agentio:telegram notify <event> <id>). */
    public const array EVENTS = ['planned', 'review', 'merged', 'blocked', 'limit', 'resumed'];

    /** The events about the loop as a whole: the id (the session that ran into the limit) is optional. */
    public const array LOOP_EVENTS = ['limit', 'resumed'];

    public function __construct(
        private Tools $tools,
        private Settings $settings,
    ) {}

    /**
     * The questions of an [AGENT:BLOCKED] comment, to be answered with a reply.
     */
    public static function question(Comment $comment): string
    {
        $body = trim((string) preg_replace('/^\[AGENT:BLOCKED\]\s*/', '', ltrim($comment->text)));
        // How to answer in YouTrack is replaced by how to answer here.
        $body = trim((string) preg_replace('/^\*\*Как ответить:?\*\*.*?(?=\n\s*\n|\z)/msu', '', $body));
        $title = '❓ **Вопросы по '.$comment->issueId.'**'.($comment->issueSummary === null ? '' : ' «'.$comment->issueSummary.'»');

        return $title."\n\n".$body."\n\n".'↩️ Ответьте на это сообщение (reply) текстом или голосовым: «В1: б; В2: а», «принимаю рекомендации» или своими словами. Ответ запишу комментарием в '.$comment->issueId.' и верну задачу в работу.';
    }

    /**
     * The report of an event of the loop; $until (a Unix time) and $window (five_hour, seven_day, ...) belong to
     * the limit event: when the loop resumes and which limit was hit.
     */
    public function event(string $event, string $id, ?string $name = null, ?int $until = null, ?string $window = null): string
    {
        if ($event === 'limit') {
            return $this->limit($id, $until, $window);
        }

        if ($event === 'resumed') {
            return '▶️ Лимит Claude Code сброшен: продолжаю работу с того места, где остановился.';
        }

        $issue = $this->issue($id);
        $title = $id.($issue === null ? '' : ' «'.self::summary($issue).'»');
        $base = $this->settings->baseBranch();

        return match ($event) {
            'planned' => '🗂 Идея '.$title.' спланирована.'.$this->epicsOf($id),
            'review' => '✅ Эпик '.$title.' готов к приёмке: ветка '.$id.'.'.$this->done($id)."\n\nПринять: панель /agentio или php artisan agentio:accept {$id}.",
            'merged' => '🚀 Эпик '.$title.' принят автоматически: слит в '.$base.' и закрыт.',
            'blocked' => '⛔ '.$title.': сессия '.($name !== null && str_starts_with($name, 'plan-') ? 'планирования' : 'эпика').' несколько раз подряд оборвалась без результата, задача в Blocked. Лог: php artisan agentio:log '.($name ?? $id).'.',
            default => 'ℹ️ '.$title.': '.$event.'.',
        };
    }

    private function limit(string $id, ?int $until, ?string $window): string
    {
        $text = '⏸ Claude Code упёрся в '.UsageLimit::windowLabel($window).($id === '' ? '' : ' (сессия '.$id.')').': новые сессии не запускаю. Задачи остаются в своих статусах, в Blocked ничего не перевожу.';

        if ($until === null) {
            return $text.' Продолжу автоматически, когда лимит сбросится.';
        }

        $timezone = Settings::string('agentio.timezone') ?? SystemTimezone::detect() ?? (string) config('app.timezone', 'UTC');
        $resumesAt = CarbonImmutable::createFromTimestampUTC($until)->setTimezone($timezone);
        $today = CarbonImmutable::now($timezone);
        $date = $resumesAt->isSameDay($today) ? '' : ' '.$resumesAt->format('d.m');

        return $text.' Продолжу автоматически в '.$resumesAt->format('H:i').$date.' ('.$timezone.').';
    }

    private function issue(string $id): ?Issue
    {
        try {
            return Issue::fromMcp($this->tools->issue($id));
        } catch (Throwable) {
            return null;
        }
    }

    private static function summary(Issue $issue): string
    {
        return TelegramText::limit((string) preg_replace('/^\[[A-Z]+\]\s*/', '', $issue->summary), 100);
    }

    /**
     * The epics planned from an idea.
     */
    private function epicsOf(string $idea): string
    {
        try {
            $epics = $this->tools->searchIssues('project: '.$this->settings->project().' Type: Epic relates to: '.$idea);
        } catch (Throwable) {
            return '';
        }

        $lines = [];

        foreach ($epics as $raw) {
            $epic = Issue::fromMcp($raw);
            $lines[] = '• '.$epic->id.' '.self::summary($epic).' — '.($epic->state() ?? '?');
        }

        return $lines === [] ? '' : "\n\nЭпики:\n".implode("\n", $lines);
    }

    /**
     * The first lines of what the last [AGENT:DONE] of the epic says was done.
     */
    private function done(string $epic): string
    {
        try {
            $done = AgentComments::fromComments($this->tools->comments($epic))->last(AgentCommentKind::Done);
        } catch (Throwable) {
            return '';
        }

        if ($done === null) {
            return '';
        }

        $body = $done->body();

        if (preg_match('/\*\*Сделано:?\*\*:?\s*(.+?)(?=\n\s*\*\*|\z)/su', $body, $match) === 1) {
            $body = $match[1];
        }

        $lines = array_slice(array_values(array_filter(array_map(trim(...), explode("\n", $body)), fn (string $line): bool => $line !== '')), 0, 5);

        return $lines === [] ? '' : "\n\nСделано:\n".TelegramText::limit(implode("\n", $lines), 800);
    }
}
