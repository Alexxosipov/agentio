<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Review;

use Illuminate\Support\Facades\Process;
use Obrazmisli\Agentio\Process\AgentCommentKind;
use Obrazmisli\Agentio\Process\AgentComments;
use Obrazmisli\Agentio\Process\ReadinessGraph;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueRepository;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * The developer stops the development of an epic for a while, from the command line, the dashboard or the Telegram
 * bot, and lifts the pause later. A pause is an [AGENT:PAUSE] comment and Stage On Hold; the claim (the
 * agent-claimed tag, the [AGENT:START] owner), the worktree and the branch stay, so the same owner continues after
 * the pause. By default the pause is soft: the orchestrator sees On Hold before its next wave of tasks and ends
 * its session; now stops the session of the epic right away (SIGTERM to its process group), its tasks in progress
 * are resumed after the pause like after any break. The epics that wait for the paused one (they depend on it,
 * directly or through another epic) are named, so the developer knows what else stands still.
 * Issues are read through the REST API and changed through the MCP server, like the agents do.
 */
final readonly class EpicPause
{
    /** The stages an epic may be paused in: Review waits for the human already, Done is over. */
    public const array PAUSABLE = [State::Backlog, State::Analysis, State::Ready, State::InProgress, State::Blocked];

    /** The stages an epic returns to after the pause, when it is not claimed (a claimed one goes In Progress). */
    public const array RETURNS_TO = [State::Backlog, State::Analysis, State::Ready, State::Blocked];

    public function __construct(
        private IssueRepository $issues,
        private Tools $tools,
        private LoopState $loop,
    ) {}

    /**
     * Pause the epic: soft (the session finishes its current wave of tasks) or now (the session is stopped).
     *
     * @param  string  $source  Where the developer paused it (CLI, панель, Telegram), for the comment
     * @return array{epic: string, summary: string, previous: string, now: bool, session: bool, stopped: bool, claimed: bool, owner: string|null, dependents: list<array{id: string, summary: string, state: string|null, via: string|null}>, already: bool}
     *
     * @throws ReviewException
     */
    public function pause(string $epicId, bool $now = false, ?string $reason = null, string $source = 'CLI'): array
    {
        $graph = $this->graph();
        $epic = $this->epic($graph, $epicId);
        $session = $this->loop->session($epic->id);
        $pid = $session !== null && $session->alive ? $session->pid : null;
        $alive = $pid !== null;
        $owner = $this->owner($epic->id);
        $dependents = $this->dependents($graph, $epic->id);

        if ($epic->hasState(State::OnHold)) {
            $stopped = $now && $pid !== null && $this->stop($pid);

            return [...$this->result($epic, $this->previous($epic->id) ?? State::OnHold->value, $now, $alive, $stopped, $owner, $dependents), 'already' => true];
        }

        if (! $epic->hasState(...self::PAUSABLE)) {
            throw new ReviewException(match (true) {
                $epic->hasState(State::Review) => "Эпик {$epic->id} уже ждёт приёмки (Review): его не нужно ставить на паузу — слейте pull request или верните эпик на доработку.",
                $epic->hasState(State::Done) => "Эпик {$epic->id} уже принят (Done): останавливать нечего.",
                default => "Эпик {$epic->id} сейчас в статусе ".($epic->state() ?? 'без статуса').': его нельзя поставить на паузу.',
            });
        }

        $previous = (string) $epic->state();

        try {
            $this->tools->addComment($epic->id, self::pauseComment($epic->id, $previous, $now, $alive, $reason, $source, $owner, $dependents));
            $this->tools->updateFields($epic->id, [State::FIELD => State::OnHold->value]);
        } catch (YouTrackException $exception) {
            throw new ReviewException('YouTrack: '.$exception->getMessage(), 502);
        }

        $stopped = $now && $pid !== null && $this->stop($pid);

        return [...$this->result($epic, $previous, $now, $alive, $stopped, $owner, $dependents), 'already' => false];
    }

    /**
     * Lift the pause: a claimed epic goes back In Progress (the loop of its owner resumes it in the same worktree),
     * one without a claim to the Stage it had before the pause (Ready when that is unknown).
     *
     * @return array{epic: string, summary: string, state: string, claimed: bool, owner: string|null, dependents: list<array{id: string, summary: string, state: string|null, via: string|null}>}
     *
     * @throws ReviewException
     */
    public function resume(string $epicId, string $source = 'CLI'): array
    {
        $graph = $this->graph();
        $epic = $this->epic($graph, $epicId);

        if (! $epic->hasState(State::OnHold)) {
            throw new ReviewException("Эпик {$epic->id} не на паузе (сейчас: ".($epic->state() ?? 'без статуса').').');
        }

        $owner = $this->owner($epic->id);
        $claimed = $epic->isClaimed() && $owner !== null;
        $previous = State::tryFrom((string) $this->previous($epic->id));
        $state = match (true) {
            $claimed => State::InProgress,
            $previous !== null && in_array($previous, self::RETURNS_TO, true) => $previous,
            default => State::Ready,
        };

        try {
            $this->tools->addComment($epic->id, "[AGENT:RESUME]\n**Пауза снята** (разработчик, {$source}): Stage → {$state->value}."
                .($claimed ? "\nЗахват сохранён за `{$owner}`: цикл этой машины продолжит эпик в том же worktree." : ''));
            $this->tools->updateFields($epic->id, [State::FIELD => $state->value]);
        } catch (YouTrackException $exception) {
            throw new ReviewException('YouTrack: '.$exception->getMessage(), 502);
        }

        return [
            'epic' => $epic->id,
            'summary' => self::summary($epic),
            'state' => $state->value,
            'claimed' => $claimed,
            'owner' => $owner,
            'dependents' => $this->dependents($graph, $epic->id),
        ];
    }

    /**
     * The message for the developer about a pause (the result of pause()), in Russian.
     *
     * @param  array{epic: string, summary: string, previous: string, now: bool, session: bool, stopped: bool, claimed: bool, owner: string|null, dependents: list<array{id: string, summary: string, state: string|null, via: string|null}>, already: bool}  $result
     */
    public static function pausedMessage(array $result): string
    {
        $epic = $result['epic'].($result['summary'] === '' ? '' : ' «'.$result['summary'].'»');
        $lines = [match (true) {
            $result['already'] && $result['stopped'] => "⏹ Эпик {$epic} уже на паузе; сессию по нему остановил сейчас.",
            $result['already'] => "⏸ Эпик {$epic} уже на паузе.",
            $result['stopped'] => "⏹ Эпик {$epic} на паузе: сессию по нему остановил сразу. Незаконченные задачи продолжатся после паузы.",
            $result['now'] || ! $result['session'] => "⏸ Эпик {$epic} на паузе: новых задач по нему агенты не берут.",
            default => "⏸ Эпик {$epic} на паузе: агенты доделают текущую волну задач и остановятся.",
        }];

        if (! $result['already']) {
            $lines[] = $result['claimed']
                ? 'Захват, ветка и worktree сохранены: после паузы эпик продолжит тот же исполнитель ('.$result['owner'].').'
                : 'Ветка и worktree, если есть, сохранены.';
        }

        $lines[] = self::dependentsLine($result['dependents'], 'Пока он на паузе, стоят и зависимые эпики');
        $lines[] = 'Продолжить: «продолжи '.$result['epic'].'» в боте, кнопка «Продолжить» на панели /agentio или php artisan agentio:resume '.$result['epic'].'.';

        return implode("\n", array_filter($lines));
    }

    /**
     * The message for the developer about a lifted pause (the result of resume()), in Russian.
     *
     * @param  array{epic: string, summary: string, state: string, claimed: bool, owner: string|null, dependents: list<array{id: string, summary: string, state: string|null, via: string|null}>}  $result
     */
    public static function resumedMessage(array $result): string
    {
        $epic = $result['epic'].($result['summary'] === '' ? '' : ' «'.$result['summary'].'»');

        return implode("\n", array_filter([
            "▶️ Пауза эпика {$epic} снята, Stage → {$result['state']}: ".($result['claimed']
                ? 'цикл продолжит его в том же worktree на ближайшем проходе.'
                : ($result['state'] === State::Ready->value ? 'цикл возьмёт его, когда подойдёт очередь.' : 'дальше — как до паузы.')),
            self::dependentsLine($result['dependents'], 'Зависимые эпики снова пойдут после него'),
        ]));
    }

    /**
     * @param  list<array{id: string, summary: string, state: string|null, via: string|null}>  $dependents
     */
    private static function dependentsLine(array $dependents, string $title): ?string
    {
        if ($dependents === []) {
            return null;
        }

        return '⚠️ '.$title.":\n".implode("\n", array_map(
            fn (array $dependent): string => '• '.$dependent['id'].($dependent['summary'] === '' ? '' : ' «'.$dependent['summary'].'»').' — '.($dependent['state'] ?? '?').($dependent['via'] === null ? '' : ', через '.$dependent['via']),
            $dependents,
        ));
    }

    /**
     * @param  list<array{id: string, summary: string, state: string|null, via: string|null}>  $dependents
     */
    private static function pauseComment(string $epicId, string $previous, bool $now, bool $session, ?string $reason, string $source, ?string $owner, array $dependents): string
    {
        $mode = match (true) {
            ! $session => 'сессия по эпику сейчас не идёт: новых задач агенты не берут.',
            $now => 'немедленная — сессия эпика остановлена; незаконченные задачи продолжатся после паузы.',
            default => 'мягкая — сессия доделает текущую волну задач и остановится, новых задач не берёт.',
        };
        $waiting = $dependents === [] ? 'нет' : implode(', ', array_map(
            fn (array $dependent): string => $dependent['id'].' ('.($dependent['state'] ?? '?').($dependent['via'] === null ? '' : ', через '.$dependent['via']).')',
            $dependents,
        ));

        return implode("\n", [
            '[AGENT:PAUSE]',
            "**Эпик поставлен на паузу** (разработчик, {$source})".($reason === null || trim($reason) === '' ? '.' : ': '.trim($reason)),
            "**Режим:** {$mode}",
            "**Stage до паузы:** {$previous}",
            '**Захват:** '.($owner === null ? 'нет.' : "сохранён за `{$owner}` — после паузы продолжит тот же исполнитель."),
            "**Ждут этот эпик:** {$waiting}",
            "**Как продолжить:** `php artisan agentio:resume {$epicId}`, кнопка «Продолжить» на панели `/agentio` или «продолжи {$epicId}» в Telegram-боте. Пока эпик в On Hold, агенты его не захватывают и не меняют его Stage.",
        ]);
    }

    /**
     * @param  list<array{id: string, summary: string, state: string|null, via: string|null}>  $dependents
     * @return array{epic: string, summary: string, previous: string, now: bool, session: bool, stopped: bool, claimed: bool, owner: string|null, dependents: list<array{id: string, summary: string, state: string|null, via: string|null}>}
     */
    private function result(Issue $epic, string $previous, bool $now, bool $session, bool $stopped, ?string $owner, array $dependents): array
    {
        return [
            'epic' => $epic->id,
            'summary' => self::summary($epic),
            'previous' => $previous,
            'now' => $now,
            'session' => $session,
            'stopped' => $stopped,
            'claimed' => $epic->isClaimed() && $owner !== null,
            'owner' => $owner,
            'dependents' => $dependents,
        ];
    }

    /**
     * Stop the session: SIGTERM to its process group (the loop starts sessions with setsid), else to the process.
     */
    private function stop(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        return Process::run(['kill', '-TERM', '--', '-'.$pid])->successful()
            || Process::run(['kill', '-TERM', (string) $pid])->successful();
    }

    /**
     * @return list<array{id: string, summary: string, state: string|null, via: string|null}>
     */
    private function dependents(ReadinessGraph $graph, string $epicId): array
    {
        return array_map(fn (array $dependent): array => [
            'id' => $dependent['id'],
            'summary' => self::summary($graph->get($dependent['id'])),
            'state' => $graph->get($dependent['id'])->state(),
            'via' => $dependent['via'],
        ], $graph->dependents($epicId));
    }

    /**
     * The Stage the last [AGENT:PAUSE] of the epic recorded, or null.
     */
    private function previous(string $epicId): ?string
    {
        $pause = $this->agentComments($epicId)->last(AgentCommentKind::Pause);

        return $pause !== null && preg_match('/\*\*Stage до паузы:\*\*\s*([A-Za-z ]+?)\s*$/mu', $pause->text, $match) === 1 ? $match[1] : null;
    }

    private function owner(string $epicId): ?string
    {
        return $this->agentComments($epicId)->claimOwner();
    }

    /**
     * @throws ReviewException
     */
    private function agentComments(string $epicId): AgentComments
    {
        try {
            return $this->issues->agentComments($epicId);
        } catch (YouTrackException $exception) {
            throw new ReviewException('YouTrack: '.$exception->getMessage(), 502);
        }
    }

    /**
     * @throws ReviewException
     */
    private function graph(): ReadinessGraph
    {
        if (! $this->issues->client()->isConfigured() || ! $this->tools->isConfigured()) {
            throw new ReviewException('YouTrack не настроен: задайте YOUTRACK_URL и YOUTRACK_TOKEN.', 503);
        }

        try {
            return $this->issues->graph();
        } catch (YouTrackException $exception) {
            throw new ReviewException('YouTrack: '.$exception->getMessage(), 502);
        }
    }

    /**
     * @throws ReviewException
     */
    private function epic(ReadinessGraph $graph, string $id): Issue
    {
        $epic = $graph->find(strtoupper(trim($id))) ?? throw new ReviewException("Задача {$id} не найдена.", 404);

        return $epic->hasType(IssueType::Epic) ? $epic : throw new ReviewException("{$epic->id} — не эпик: на паузу ставится только эпик.", 422);
    }

    private static function summary(Issue $issue): string
    {
        return mb_strimwidth((string) preg_replace('/^\[[A-Z]+\]\s*/', '', $issue->summary), 0, 100, '…');
    }
}
