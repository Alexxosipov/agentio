<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Process\AgentCommentKind;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Runtime\Session;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueRepository;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\YouTrackException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * A summary of the autonomous cycle: the local loop and its live sessions, and the YouTrack project
 * (counts by Type × Stage, ideas and ready epics, work in progress, blocked issues with their reasons,
 * epics awaiting a human).
 */
#[AsCommand(name: 'agentio:status')]
final class StatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agentio:status
        {--json : Print the summary as JSON}
        {--local : Only the local loop and sessions, without YouTrack}';

    /**
     * @var string
     */
    protected $description = 'Show the state of the autonomous development loop and of the YouTrack project';

    public function handle(LoopState $loop, IssueRepository $issues): int
    {
        $status = [
            'loop' => $this->loop($loop),
            'sessions' => $this->sessions($loop),
            'youtrack' => $this->option('local') ? null : $this->youTrack($issues),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->render($status);

        return self::SUCCESS;
    }

    /**
     * @return array{status: string, pid: int|null, stopRequested: bool, stopFile: string, logsPath: string, lastLog: array{time: string|null, message: string, issueId: string|null}|null}
     */
    private function loop(LoopState $loop): array
    {
        $log = $loop->loopLog(1);

        return [
            'status' => $loop->status()->value,
            'pid' => $loop->loopPid(),
            'stopRequested' => $loop->isStopRequested(),
            'stopFile' => $loop->stopFile(),
            'logsPath' => $loop->logsPath(),
            'lastLog' => $log === [] ? null : $log[0]->toArray(),
        ];
    }

    /**
     * Live sessions with their latest event.
     *
     * @return list<array{name: string, kind: string, issueId: string, pid: int|null, startedAt: string|null, restarts: int, log: string, lastEvent: array{type: string, time: string|null, text: string}|null}>
     */
    private function sessions(LoopState $loop): array
    {
        return array_map(function (Session $session) use ($loop): array {
            $event = $loop->sessionLog($session->name)->tail(1)[0] ?? null;

            return [
                'name' => $session->name,
                'kind' => $session->kind->value,
                'issueId' => $session->issueId,
                'pid' => $session->pid,
                'startedAt' => $session->startedAt?->toIso8601String(),
                'restarts' => $session->restarts,
                'log' => $session->logPath,
                'lastEvent' => $event === null ? null : [
                    'type' => $event->type->value,
                    'time' => $event->time?->toIso8601String(),
                    'text' => mb_strimwidth($event->text, 0, 200, '…'),
                ],
            ];
        }, $loop->runningSessions());
    }

    /**
     * @return array<string, mixed>
     */
    private function youTrack(IssueRepository $issues): array
    {
        $summary = ['configured' => $issues->client()->isConfigured(), 'project' => $issues->project(), 'url' => $issues->client()->baseUrl()];

        if (! $summary['configured']) {
            return [...$summary, 'error' => 'YOUTRACK_URL / YOUTRACK_TOKEN are not set'];
        }

        try {
            $graph = $issues->graph();
            $all = $graph->issues();
            $counts = [];

            foreach ($all as $issue) {
                $type = $issue->type() ?? '-';
                $state = $issue->state() ?? '-';
                $counts[$type][$state] = ($counts[$type][$state] ?? 0) + 1;
            }

            $brief = fn (Issue $issue): array => $this->brief($issue, $issues);
            $pick = fn (callable $filter): array => array_values(array_map($brief, array_filter($all, $filter)));

            $blocked = array_map(function (Issue $issue) use ($issues, $brief): array {
                $reason = $issues->agentComments($issue->id)->last(AgentCommentKind::Blocked);

                return [...$brief($issue), 'reason' => $reason?->body(), 'since' => $reason?->createdAt?->toIso8601String()];
            }, array_values(array_filter($all, fn (Issue $issue): bool => $issue->hasState(State::Blocked))));

            return [
                ...$summary,
                'counts' => $counts,
                'ideas' => $pick(fn (Issue $issue): bool => $issue->isIdea() && $issue->hasState(State::Backlog) && ! $issue->isClaimed()),
                'readyEpics' => array_map(fn (string $id): array => [...$brief($graph->get($id)), 'readyTasks' => $graph->readyTasks($id)], $graph->readyEpics()),
                'inProgress' => $pick(fn (Issue $issue): bool => $issue->hasState(State::InProgress) || $issue->isClaimed()),
                'blocked' => $blocked,
                'awaitingHuman' => $pick(fn (Issue $issue): bool => $issue->hasState(State::Review) && $issue->hasType(IssueType::Epic, IssueType::Idea)),
            ];
        } catch (YouTrackException $exception) {
            return [...$summary, 'error' => $exception->getMessage()];
        }
    }

    /**
     * @return array{id: string, type: string|null, state: string|null, summary: string, url: string}
     */
    private function brief(Issue $issue, IssueRepository $issues): array
    {
        return [
            'id' => $issue->id,
            'type' => $issue->type(),
            'state' => $issue->state(),
            'summary' => $issue->summary,
            'url' => $issues->url($issue->id),
        ];
    }

    /**
     * @param  array{loop: array<string, mixed>, sessions: list<array<string, mixed>>, youtrack: array<string, mixed>|null}  $status
     */
    private function render(array $status): void
    {
        $loop = $status['loop'];
        $this->line('<options=bold>Agent loop</>');
        $this->components->twoColumnDetail('Status', (string) $loop['status'].($loop['pid'] === null ? '' : ' (pid '.$loop['pid'].')'));
        $this->components->twoColumnDetail('Stop flag', $loop['stopRequested'] ? '<fg=yellow>set</> '.$loop['stopFile'] : 'no');

        if (is_array($loop['lastLog'])) {
            $this->components->twoColumnDetail('Last loop.log entry', trim(($loop['lastLog']['time'] ?? '').' '.$loop['lastLog']['message']));
        }

        $this->newLine();
        $this->line('<options=bold>Live sessions</>');

        if ($status['sessions'] === []) {
            $this->line('  none');
        }

        foreach ($status['sessions'] as $session) {
            $event = is_array($session['lastEvent']) ? $session['lastEvent'] : null;
            $this->line(sprintf(
                '  %s %s (pid %s, since %s)',
                $session['kind'] === 'plan' ? '/agentio-plan' : '/agentio-work-epic',
                $session['issueId'],
                $session['pid'] ?? '?',
                $session['startedAt'] ?? '?',
            ));
            $this->line('    last event: '.($event === null ? '—' : trim(($event['time'] ?? '').' '.$event['type'].': '.$event['text'])));
        }

        $youTrack = $status['youtrack'];

        if ($youTrack === null) {
            return;
        }

        $this->newLine();
        $this->line('<options=bold>YouTrack project '.$youTrack['project'].'</>');

        if (isset($youTrack['error'])) {
            $this->components->warn((string) $youTrack['error']);

            return;
        }

        foreach ((array) $youTrack['counts'] as $type => $states) {
            $this->line(sprintf('  %-6s %s', $type, implode('  ', array_map(
                fn (string $state, int $count): string => $state.'='.$count,
                array_keys((array) $states),
                array_values((array) $states),
            ))));
        }

        $sections = [
            'ideas' => 'Ideas waiting for planning',
            'readyEpics' => 'Ready epics',
            'inProgress' => 'In progress / claimed',
            'blocked' => 'Blocked (needs a human)',
            'awaitingHuman' => 'Awaiting a human (Review)',
        ];

        foreach ($sections as $key => $title) {
            $this->newLine();
            $this->line('<options=bold>'.$title.'</>');
            $items = (array) $youTrack[$key];

            if ($items === []) {
                $this->line('  none');
            }

            foreach ($items as $item) {
                $item = (array) $item;
                $this->line(sprintf('  %s %s [%s] %s', $item['id'], $item['type'] ?? '-', $item['state'] ?? '-', $item['summary']));

                if ($key === 'blocked') {
                    $this->line('    '.str_replace("\n", "\n    ", trim((string) ($item['reason'] ?? '(no [AGENT:BLOCKED] comment)'))));
                }

                if ($key === 'readyEpics') {
                    $this->line('    first wave: '.implode(', ', (array) $item['readyTasks']));
                }
            }
        }
    }
}
