<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Review;

use Closure;
use Obrazmisli\Agentio\Git\Git;
use Obrazmisli\Agentio\Git\RepositoryLock;
use Obrazmisli\Agentio\GitHub\GitHub;
use Obrazmisli\Agentio\GitHub\GitHubException;
use Obrazmisli\Agentio\GitHub\PullRequest;
use Obrazmisli\Agentio\Process\ReadinessGraph;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueRepository;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * The human's decision on an epic in Review, taken from the dashboard, the command line or the Telegram bot:
 * accept it (merge the pull request of the epic branch into the development branch on GitHub — opening it first
 * when there is none —, bring the local development branch up to date, remove the worktree, close the stories
 * and the epic) or send it back (a TASK with the remark in a story, the story and the epic back to Ready, so the
 * loop resumes the epic in the same worktree). Epics reach the development branch only through pull requests.
 * Issues are read through the REST API and changed through the MCP server, like the agents do. One action runs at
 * a time per repository, whatever starts it.
 */
final readonly class EpicAcceptance
{
    /** The name of the repository lock (RepositoryLock) actions run under. */
    public const string LOCK = 'acceptance';

    /** Characters of the remark used in the summary of the TASK. */
    public const int HEADLINE = 80;

    public function __construct(
        private Settings $settings,
        private IssueRepository $issues,
        private Tools $tools,
        private LoopState $loop,
        private EpicPullRequest $pullRequests,
        private GitHub $github,
    ) {}

    /**
     * What accepting the epic needs, and its pull request (null when it has none or GitHub could not be asked);
     * ok of a check is null when it cannot be told in advance.
     *
     * @return array{checks: list<array{key: string, label: string, ok: bool|null, detail: string|null}>, pullRequest: PullRequest|null}
     */
    public function assess(?Issue $epic, EpicBranch $branch): array
    {
        $checks = [
            self::check('state', 'Эпик в статусе Review', $epic?->hasState(State::Review), match (true) {
                $epic === null => 'нет данных из YouTrack',
                $epic->hasState(State::Review) => null,
                default => 'сейчас: '.($epic->state() ?? 'без статуса'),
            }),
            self::check('session', 'Сессия агента по эпику не запущена', ! $this->sessionAlive($branch->epicId), null),
        ];

        $worktree = $branch->worktreeGit();

        if ($worktree !== null) {
            $changes = $worktree->changes(untracked: true);
            $checks[] = self::check('worktree', 'В worktree эпика всё закоммичено', $changes === [], self::listed($changes));
        }

        $pullRequest = null;

        try {
            $this->pullRequests->ensureReady($branch);
            $pullRequest = $this->pullRequests->current($branch);
            $checks[] = self::check('github', 'GitHub: есть origin, gh установлен и авторизован', true, null);
        } catch (ReviewException $exception) {
            $checks[] = self::check('github', 'GitHub: есть origin, gh установлен и авторизован', false, $exception->getMessage());
        }

        if ($branch->isMerged()) {
            return ['checks' => $checks, 'pullRequest' => $pullRequest];
        }

        $ahead = $branch->divergence()['ahead'];
        $checks[] = self::check('commits', 'В ветке эпика есть коммиты', $ahead > 0, $ahead > 0 ? null : 'ветка не отличается от '.$branch->base);

        $open = $pullRequest?->isOpen() === true ? $pullRequest : null;
        $checks[] = match (true) {
            $open === null => self::check('pr', 'Pull request в '.$branch->base, null, 'ещё не открыт: приёмка запушит ветку и откроет его'),
            $open->draft => self::check('pr', 'Pull request в '.$branch->base, false, $open->label().' — черновик (draft): переведите его в Ready for review'),
            default => self::check('pr', 'Pull request в '.$branch->base, true, $open->label().' '.$open->url),
        };

        if ($open !== null) {
            $checks[] = self::check('conflicts', 'Слияние без конфликтов', $open->canMerge(), match ($open->canMerge()) {
                true => null,
                false => 'PR конфликтует с '.$branch->base.': влейте '.$branch->base.' в ветку эпика и разрешите конфликты',
                null => 'GitHub ещё проверяет',
            });
        } else {
            $conflicts = $branch->conflicts();
            $checks[] = self::check('conflicts', 'Слияние без конфликтов', $conflicts === null ? null : $conflicts === [], $conflicts === null ? 'заранее проверить нельзя (нужен git 2.38+): конфликт остановит слияние' : self::listed($conflicts));
        }

        return ['checks' => $checks, 'pullRequest' => $pullRequest];
    }

    /**
     * Accept the epic: merge its pull request on GitHub (pushing the branch and opening the pull request first when
     * needed; nothing to merge when the development branch already has the epic), bring the local development
     * branch up to date, then optionally remove the worktree, delete the local branch and move the reviewed stories
     * and the epic to Done. Steps after the merge never undo it: their failures come back as warnings.
     *
     * @return array{merged: bool, commit: string|null, branch: string, base: string, pullRequest: array<string, mixed>|null, worktreeRemoved: bool, branchDeleted: bool, closed: list<string>, warnings: list<string>}
     *
     * @throws ReviewException
     */
    public function accept(string $epicId, bool $removeWorktree = true, bool $deleteBranch = false, bool $close = true): array
    {
        return $this->locked(function () use ($epicId, $removeWorktree, $deleteBranch, $close): array {
            $graph = $this->graph();
            $epic = $this->epic($graph, $epicId);
            $branch = EpicBranch::find($this->settings, $epicId)
                ?? throw new ReviewException("В главном каталоге нет ветки {$epicId}.");

            $this->pullRequests->ensureReady($branch);
            $branch->git->fetch($branch->base);

            // A branch without commits of its own looks merged to git: tell it from a merged pull request.
            if ($branch->isMerged() && ! $this->baseHasWorkOf($graph, $epic, $branch)) {
                throw new ReviewException("В {$branch->base} нет коммитов задач эпика, а ветка {$branch->name} не отличается от неё: принимать нечего.", 409);
            }

            $assessment = $this->assess($epic, $branch);
            $failed = array_filter($assessment['checks'], fn (array $check): bool => $check['ok'] === false);

            if ($failed !== []) {
                throw new ReviewException('Эпик сейчас нельзя принять.', 409, array_values(array_map(
                    fn (array $check): string => $check['label'].($check['detail'] === null ? '' : ' — '.$check['detail']),
                    $failed,
                )));
            }

            $warnings = [];
            $pullRequest = $assessment['pullRequest'];
            $commit = null;

            if (! $branch->isMerged()) {
                $pullRequest = $this->pullRequests->publishBranch($branch)['pullRequest'];
                $commit = $this->merge($branch, $pullRequest);
                $branch->git->fetch($branch->base);
            }

            $behind = $branch->git->fastForward($branch->base);

            if ($behind !== null) {
                $warnings[] = 'Локальная ветка '.$branch->base.' не обновлена: '.$behind;
            }

            return [
                'merged' => $commit !== null,
                'commit' => $commit,
                'branch' => $branch->name,
                'base' => $branch->base,
                'pullRequest' => $pullRequest?->toArray(),
                'worktreeRemoved' => $removeWorktree && $this->removeWorktree($branch, $warnings),
                'branchDeleted' => $deleteBranch && $this->deleteBranch($branch, $warnings),
                'closed' => $close ? $this->close($graph, $epic, $branch, $pullRequest, $commit, $warnings) : [],
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * Send the epic back: a Ready TASK with the remark in the story, the remark as a comment of the story,
     * the story and the epic back to Ready.
     *
     * @return array{task: string, warnings: list<string>}
     *
     * @throws ReviewException
     */
    public function rework(string $epicId, string $storyId, string $remark): array
    {
        return $this->locked(function () use ($epicId, $storyId, $remark): array {
            $graph = $this->graph();
            $epic = $this->epic($graph, $epicId);

            if (! $epic->hasState(State::Review)) {
                throw new ReviewException('Вернуть на доработку можно только эпик в Review, сейчас: '.($epic->state() ?? 'без статуса').'.');
            }

            if ($this->sessionAlive($epicId)) {
                throw new ReviewException('По эпику работает сессия агента: дождитесь её завершения.');
            }

            $story = $graph->find($storyId);

            if ($story === null || ! $story->hasType(IssueType::Story) || $graph->parentOf($storyId) !== $epicId) {
                throw new ReviewException("{$storyId} — не история эпика {$epicId}.", 422);
            }

            try {
                $task = $this->tools->createIssue(
                    $this->issues->project(),
                    '[TASK] Review: '.self::headline($remark),
                    self::taskDescription($epicId, $storyId, $remark),
                    $storyId,
                    [IssueType::FIELD => IssueType::Task->value, State::FIELD => State::Ready->value],
                );
            } catch (YouTrackException $exception) {
                throw new ReviewException('YouTrack: '.$exception->getMessage(), 502);
            }

            $warnings = [];

            try {
                $this->tools->addComment($storyId, "Замечание при приёмке эпика {$epicId} (панель agentio), задача {$task}:\n\n{$remark}");

                if (! $story->hasState(State::Ready)) {
                    $this->tools->updateFields($storyId, [State::FIELD => State::Ready->value]);
                }

                $this->tools->addComment($epicId, "Эпик возвращён на доработку в панели agentio: замечание к {$storyId}, задача {$task}.");
                $this->tools->updateFields($epicId, [State::FIELD => State::Ready->value]);
            } catch (YouTrackException $exception) {
                $warnings[] = 'YouTrack: '.$exception->getMessage()." Задача {$task} создана; верните {$storyId} и {$epicId} в Ready вручную.";
            }

            return ['task' => $task, 'warnings' => $warnings];
        });
    }

    /**
     * Run an action under the acceptance lock.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $action
     * @return TResult
     *
     * @throws ReviewException
     */
    private function locked(Closure $action): mixed
    {
        $result = (new RepositoryLock(new Git($this->settings->basePath()), self::LOCK))->attempt($action);

        return $result === null
            ? throw new ReviewException('Другое действие приёмки ещё выполняется: повторите позже.')
            : $result[0];
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
        $epic = $graph->find($id) ?? throw new ReviewException("Задача {$id} не найдена.", 404);

        return $epic->hasType(IssueType::Epic) ? $epic : throw new ReviewException("{$id} — не эпик.", 422);
    }

    private function sessionAlive(string $epicId): bool
    {
        return $this->loop->session($epicId)?->alive === true;
    }

    /**
     * Merge the pull request on GitHub with a merge commit, as long as its head is the local tip of the branch.
     *
     * @return string The short hash of the merge commit
     *
     * @throws ReviewException
     */
    private function merge(EpicBranch $branch, PullRequest $pullRequest): string
    {
        try {
            $commit = $this->github->merge($pullRequest, (string) $branch->sha());
        } catch (GitHubException $exception) {
            throw new ReviewException('GitHub не слил '.$pullRequest->label().' ('.$pullRequest->url.'): '.$exception->getMessage(), 409);
        }

        return substr($commit, 0, 7);
    }

    /**
     * Whether the development branch has a commit of a task of the epic ("<TASK>: …", as agentio:commit writes them).
     */
    private function baseHasWorkOf(ReadinessGraph $graph, Issue $epic, EpicBranch $branch): bool
    {
        // Issue ids have no regular expression characters: [A-Z0-9_] and a dash.
        $ids = $graph->descendants($epic->id);

        if ($ids === []) {
            return false;
        }

        foreach (['refs/heads/', 'refs/remotes/origin/'] as $prefix) {
            if ($branch->git->lines('log', '-1', '--format=%h', '-E', '--grep=^('.implode('|', $ids).')([^0-9]|$)', $prefix.$branch->base) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function removeWorktree(EpicBranch $branch, array &$warnings): bool
    {
        if ($branch->worktree === null || $branch->worktreeGit() === null) {
            return false;
        }

        $process = $branch->git->runWithTimeout(null, 'worktree', 'remove', '--force', $branch->worktree);

        if (! $process->isSuccessful()) {
            $warnings[] = 'Worktree не удалён: '.Git::error($process);
        }

        return $process->isSuccessful();
    }

    /**
     * @param  list<string>  $warnings
     */
    private function deleteBranch(EpicBranch $branch, array &$warnings): bool
    {
        $process = $branch->git->run('branch', '-d', $branch->name);

        if (! $process->isSuccessful()) {
            $warnings[] = 'Ветка не удалена: '.Git::error($process);
        }

        return $process->isSuccessful();
    }

    /**
     * Comment the acceptance on the epic, move the stories in Review to Done, then the epic when every story is Done.
     *
     * @param  list<string>  $warnings
     * @return list<string> The issues moved to Done
     */
    private function close(ReadinessGraph $graph, Issue $epic, EpicBranch $branch, ?PullRequest $pullRequest, ?string $commit, array &$warnings): array
    {
        $closed = [];
        $request = $pullRequest === null ? '' : ' (pull request '.$pullRequest->label().' '.$pullRequest->url.')';

        try {
            $this->tools->addComment($epic->id, $commit === null
                ? "Эпик принят: ветка `{$branch->name}` уже в `{$branch->base}`{$request}."
                : "Эпик принят: pull request {$pullRequest?->label()} ветки `{$branch->name}` слит в `{$branch->base}` (`{$commit}`): {$pullRequest?->url}");

            $open = [];

            foreach ($graph->children($epic->id) as $id) {
                $story = $graph->find($id);

                if ($story === null || ! $story->hasType(IssueType::Story) || $story->hasState(State::Done)) {
                    continue;
                }

                if ($story->hasState(State::Review)) {
                    $this->tools->updateFields($id, [State::FIELD => State::Done->value]);
                    $closed[] = $id;
                } else {
                    $open[] = $id.' ('.($story->state() ?? 'без статуса').')';
                }
            }

            if ($open === []) {
                $this->tools->updateFields($epic->id, [State::FIELD => State::Done->value]);
                $closed[] = $epic->id;
            } else {
                $warnings[] = 'Эпик оставлен в Review: не все истории прошли ревью — '.implode(', ', $open).'.';
            }
        } catch (YouTrackException $exception) {
            $warnings[] = 'YouTrack: '.$exception->getMessage().' Закройте истории и эпик вручную.';
        }

        return $closed;
    }

    /**
     * @return array{key: string, label: string, ok: bool|null, detail: string|null}
     */
    private static function check(string $key, string $label, ?bool $ok, ?string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    /**
     * @param  list<string>  $items
     */
    private static function listed(array $items, int $limit = 5): ?string
    {
        if ($items === []) {
            return null;
        }

        $items = array_map(trim(...), $items);

        return implode(', ', array_slice($items, 0, $limit)).(count($items) > $limit ? ' и ещё '.(count($items) - $limit) : '');
    }

    private static function headline(string $remark): string
    {
        foreach (explode("\n", $remark) as $line) {
            if (trim($line) !== '') {
                return mb_strimwidth(trim($line), 0, self::HEADLINE, '…');
            }
        }

        return 'замечание при приёмке';
    }

    private static function taskDescription(string $epicId, string $storyId, string $remark): string
    {
        return <<<MD
            ## Контекст
            Замечание человека при приёмке эпика {$epicId} к STORY {$storyId}, оставлено в панели agentio.

            ## Что сделать
            {$remark}

            ## Критерии приёмки
            - [ ] Замечание устранено, результат подтверждён тестом.
            - [ ] `composer test` проходит.

            ## Затрагиваемые области кода (оценка)
            Определи по замечанию и коду STORY {$storyId}.

            ## Вне рамок
            Всё, что не относится к замечанию.
            MD;
    }
}
