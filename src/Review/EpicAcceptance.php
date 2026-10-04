<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Review;

use Closure;
use Obrazmisli\Agentio\Git\Git;
use Obrazmisli\Agentio\Git\RepositoryLock;
use Obrazmisli\Agentio\Process\ReadinessGraph;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueRepository;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\YouTrackException;
use Throwable;

/**
 * The human's decision on an epic in Review, taken from the dashboard: accept it (merge the epic branch into
 * the base branch of the main checkout, remove the worktree, close the stories and the epic) or send it back
 * (a TASK with the remark in a story, the story and the epic back to Ready, so the loop resumes the epic in the
 * same worktree). Issues are read through the REST API and changed through the MCP server, like the agents do.
 * One action runs at a time per repository, whether it comes from the dashboard or from the agent loop
 * (php artisan agentio:accept).
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
    ) {}

    /**
     * What a merge of the epic branch needs; ok is null when it cannot be told in advance.
     *
     * @return list<array{key: string, label: string, ok: bool|null, detail: string|null}>
     */
    public function checks(?Issue $epic, EpicBranch $branch): array
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

        if (! $branch->isMerged()) {
            $ahead = $branch->divergence()['ahead'];
            $checks[] = self::check('commits', 'В ветке эпика есть коммиты', $ahead > 0, $ahead > 0 ? null : 'ветка не отличается от '.$branch->base);

            $current = $branch->git->currentBranch();
            $checks[] = self::check('checkout', 'Главный каталог на ветке '.$branch->base, $current === $branch->base, $current === $branch->base ? null : 'сейчас: '.($current ?? 'detached HEAD'));

            $changes = $branch->git->changes();
            $checks[] = self::check('clean', 'В главном каталоге нет незакоммиченных изменений', $changes === [], self::listed($changes));

            $conflicts = $branch->conflicts();
            $checks[] = self::check('conflicts', 'Слияние без конфликтов', $conflicts === null ? null : $conflicts === [], $conflicts === null ? 'заранее проверить нельзя (нужен git 2.38+): конфликт остановит слияние' : self::listed($conflicts));
        }

        return $checks;
    }

    /**
     * Accept the epic: merge its branch (unless the base already has it), then optionally remove the worktree,
     * delete the branch and move the reviewed stories and the epic to Done. Steps after the merge never undo it:
     * their failures come back as warnings.
     *
     * @return array{merged: bool, commit: string|null, branch: string, base: string, worktreeRemoved: bool, branchDeleted: bool, closed: list<string>, warnings: list<string>}
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

            // A branch without commits of its own looks merged to git: tell it from a merged pull request.
            if ($branch->isMerged() && ! $this->baseHasWorkOf($graph, $epic, $branch)) {
                throw new ReviewException("В {$branch->base} нет коммитов задач эпика, а ветка {$branch->name} не отличается от неё: принимать нечего.", 409);
            }

            $failed = array_filter($this->checks($epic, $branch), fn (array $check): bool => $check['ok'] === false);

            if ($failed !== []) {
                throw new ReviewException('Эпик сейчас нельзя принять.', 409, array_values(array_map(
                    fn (array $check): string => $check['label'].($check['detail'] === null ? '' : ' — '.$check['detail']),
                    $failed,
                )));
            }

            $warnings = [];
            $commit = $branch->isMerged() ? null : $this->merge($branch);

            return [
                'merged' => $commit !== null,
                'commit' => $commit,
                'branch' => $branch->name,
                'base' => $branch->base,
                'worktreeRemoved' => $removeWorktree && $this->removeWorktree($branch, $warnings),
                'branchDeleted' => $deleteBranch && $this->deleteBranch($branch, $warnings),
                'closed' => $close ? $this->close($graph, $epic, $branch, $commit, $warnings) : [],
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
     * Merge the branch into the base of the main checkout; a failed merge is aborted.
     *
     * @return string The short hash of the merge commit
     *
     * @throws ReviewException
     */
    private function merge(EpicBranch $branch): string
    {
        // The full ref: a tag named like the branch (TP-12) would win over a short name.
        try {
            $process = $branch->git->runWithTimeout(null, 'merge', '--no-ff', '-m', "Merge branch '{$branch->name}' into {$branch->base}", 'refs/heads/'.$branch->name);
        } catch (Throwable $exception) {
            $this->abortMerge($branch);

            throw new ReviewException('git merge прерван: '.$exception->getMessage().' Главный каталог возвращён в прежнее состояние.', 500);
        }

        if ($process->isSuccessful()) {
            return (string) $branch->git->output('rev-parse', '--short', 'HEAD');
        }

        $conflicts = $branch->git->lines('diff', '--name-only', '--diff-filter=U');
        $this->abortMerge($branch);

        throw new ReviewException($conflicts === []
            ? 'git merge не удался: '.Git::error($process)
            : 'Слияние остановлено из-за конфликтов, главный каталог возвращён в прежнее состояние. Слейте ветку вручную.', 409, $conflicts);
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

        return $branch->git->lines('log', '-1', '--format=%h', '-E', '--grep=^('.implode('|', $ids).')([^0-9]|$)', 'refs/heads/'.$branch->base) !== [];
    }

    private function abortMerge(EpicBranch $branch): void
    {
        if ($branch->git->run('rev-parse', '--quiet', '--verify', 'MERGE_HEAD')->isSuccessful()) {
            $branch->git->run('merge', '--abort');
        }
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
    private function close(ReadinessGraph $graph, Issue $epic, EpicBranch $branch, ?string $commit, array &$warnings): array
    {
        $closed = [];

        try {
            $this->tools->addComment($epic->id, $commit === null
                ? "Эпик принят в панели agentio: ветка `{$branch->name}` уже слита в `{$branch->base}`."
                : "Эпик принят в панели agentio: ветка `{$branch->name}` слита в `{$branch->base}` (`{$commit}`).");

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
            - [ ] `php artisan agentio:test` проходит.

            ## Затрагиваемые области кода (оценка)
            Определи по замечанию и коду STORY {$storyId}.

            ## Вне рамок
            Всё, что не относится к замечанию.
            MD;
    }
}
