<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Review;

use Obrazmisli\Agentio\GitHub\GitHub;
use Obrazmisli\Agentio\GitHub\GitHubException;
use Obrazmisli\Agentio\GitHub\PullRequest;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;
use Throwable;

/**
 * The pull request of an epic: the only way its branch reaches the development branch. Publishing pushes the
 * epic branch to origin (never forced) and opens the pull request into the development branch when none is
 * open, so running it again after rework only updates the pull request. The commits of the stories and tasks go
 * into the epic branch locally; nothing but the epic gets a pull request.
 */
final readonly class EpicPullRequest
{
    /** Commits listed in the description of a new pull request at most. */
    public const int COMMITS = 50;

    public function __construct(
        private Settings $settings,
        private GitHub $github,
        private Tools $tools,
    ) {}

    /**
     * Push the epic branch and make sure a pull request from it into the development branch is open.
     *
     * @return array{pullRequest: PullRequest, created: bool}
     *
     * @throws ReviewException
     */
    public function publish(string $epicId): array
    {
        $branch = EpicBranch::find($this->settings, $epicId)
            ?? throw new ReviewException("В главном каталоге нет ветки {$epicId}.", 404);

        return $this->publishBranch($branch);
    }

    /**
     * @return array{pullRequest: PullRequest, created: bool}
     *
     * @throws ReviewException
     */
    public function publishBranch(EpicBranch $branch): array
    {
        $this->ensureReady($branch);

        if ($branch->isMerged()) {
            throw new ReviewException("Всё из ветки {$branch->name} уже есть в {$branch->base}: открывать pull request не с чем.", 409);
        }

        $error = $branch->git->push($branch->name);

        if ($error !== null) {
            throw new ReviewException("git push ветки {$branch->name} в origin не удался: {$error}", 502);
        }

        try {
            $open = $this->github->current($branch->name, $branch->base);

            if ($open !== null && $open->isOpen()) {
                return ['pullRequest' => $open, 'created' => false];
            }

            $summary = $this->summary($branch->epicId);

            return ['pullRequest' => $this->github->create($branch->name, $branch->base, $branch->epicId.($summary === null ? '' : ': '.$summary), $this->body($branch, $summary)), 'created' => true];
        } catch (GitHubException $exception) {
            throw new ReviewException('GitHub: '.$exception->getMessage(), 502);
        }
    }

    /**
     * The open pull request of the epic branch, else its last merged one, else null.
     *
     * @throws ReviewException
     */
    public function current(EpicBranch $branch): ?PullRequest
    {
        try {
            return $this->github->current($branch->name, $branch->base);
        } catch (GitHubException $exception) {
            throw new ReviewException('GitHub: '.$exception->getMessage(), 502);
        }
    }

    /**
     * What publishing and merging need: an origin and a GitHub CLI that is logged in.
     *
     * @throws ReviewException
     */
    public function ensureReady(EpicBranch $branch): void
    {
        if (! $branch->git->hasRemote()) {
            throw new ReviewException('У репозитория нет remote origin: эпики принимаются только через pull request на GitHub (git remote add origin …).', 409);
        }

        try {
            $this->github->ensureReady();
        } catch (GitHubException $exception) {
            throw new ReviewException($exception->getMessage(), 409);
        }
    }

    private function body(EpicBranch $branch, ?string $summary): string
    {
        $commits = $branch->commits(self::COMMITS);
        $ahead = $branch->divergence()['ahead'];
        $lines = [
            'Эпик ['.$branch->epicId.']('.$this->tools->issueUrl($branch->epicId).')'.($summary === null ? '' : ' «'.$summary.'»').': истории и задачи эпика, проверенные ревью агентов и полным прогоном `composer test`.',
            '',
            '**Коммиты** ('.$ahead.'):',
            ...array_map(fn (array $commit): string => '- '.$commit['short'].' '.$commit['subject'], $commits),
        ];

        if ($ahead > count($commits)) {
            $lines[] = '- … и ещё '.($ahead - count($commits));
        }

        return implode("\n", [
            ...$lines,
            '',
            '---',
            "Pull request открыт agentio. Слить его и закрыть эпик в YouTrack: ответьте боту agentio в Telegram «смержи {$branch->epicId}», нажмите «Принять и слить» на странице эпика в панели `/agentio` или выполните `php artisan agentio:accept {$branch->epicId}`. Если слили здесь, на GitHub, — выполните `php artisan agentio:accept {$branch->epicId}`: он уберёт worktree и закроет эпик.",
        ]);
    }

    private function summary(string $epicId): ?string
    {
        try {
            $summary = trim((string) preg_replace('/^\[[A-Z]+\]\s*/', '', Issue::fromMcp($this->tools->issue($epicId))->summary));
        } catch (Throwable) {
            return null;
        }

        return $summary === '' ? null : $summary;
    }
}
