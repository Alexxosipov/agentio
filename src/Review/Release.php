<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Review;

use Closure;
use Obrazmisli\Agentio\Git\Git;
use Obrazmisli\Agentio\Git\RepositoryLock;
use Obrazmisli\Agentio\GitHub\GitHub;
use Obrazmisli\Agentio\GitHub\GitHubException;
use Obrazmisli\Agentio\GitHub\PullRequest;
use Obrazmisli\Agentio\Settings;

/**
 * A release: the development branch reaches the production branch only through a pull request on GitHub, and
 * only after the developer confirms it. prepare() opens the pull request (or finds the open one) and tells which
 * commit of the development branch it would release; merge() merges it only while the development branch on
 * GitHub is still at that commit, so the developer always confirms exactly what goes to production. Nothing of
 * it is a YouTrack issue.
 */
final readonly class Release
{
    /** The status of the ReviewException when the development branch moved after the developer confirmed. */
    public const int MOVED = 412;

    /** Commits listed in the description of the pull request at most. */
    public const int COMMITS = 50;

    public function __construct(
        private Settings $settings,
        private GitHub $github,
    ) {}

    /**
     * Open the release pull request (development into production) or find the open one.
     *
     * @return array{pullRequest: PullRequest, created: bool, head: string, commits: int, unpushed: int}
     *
     * @throws ReviewException
     */
    public function prepare(): array
    {
        return $this->locked(function (Git $git, string $base, string $production): array {
            $head = $this->fetch($git, $base, $production);
            $commits = (int) $git->output('rev-list', '--count', 'refs/remotes/origin/'.$production.'..refs/remotes/origin/'.$base);
            $unpushed = $git->branchExists($base) ? (int) $git->output('rev-list', '--count', 'refs/remotes/origin/'.$base.'..refs/heads/'.$base) : 0;

            try {
                $open = $this->github->current($base, $production);

                if ($open !== null && $open->isOpen()) {
                    return ['pullRequest' => $open, 'created' => false, 'head' => $head, 'commits' => $commits, 'unpushed' => $unpushed];
                }

                if ($commits === 0) {
                    throw new ReviewException("В {$base} на GitHub нет ничего нового относительно {$production}: выпускать нечего.", 409);
                }

                $pullRequest = $this->github->create($base, $production, 'Релиз '.date('Y-m-d').": {$base} → {$production}", $this->body($git, $base, $production, $commits));
            } catch (GitHubException $exception) {
                throw new ReviewException('GitHub: '.$exception->getMessage(), 502);
            }

            return ['pullRequest' => $pullRequest, 'created' => true, 'head' => $head, 'commits' => $commits, 'unpushed' => $unpushed];
        });
    }

    /**
     * Merge the release pull request $number, only while the development branch on GitHub is at $head (the commit
     * the developer confirmed), then bring the local production branch up to date.
     *
     * @return array{pullRequest: PullRequest, commit: string, warnings: list<string>}
     *
     * @throws ReviewException With status MOVED when the development branch moved since the confirmation
     */
    public function merge(int $number, string $head): array
    {
        return $this->locked(function (Git $git, string $base, string $production) use ($number, $head): array {
            $current = $this->fetch($git, $base, $production);

            try {
                $pullRequest = $this->github->find($number) ?? throw new ReviewException("Pull request #{$number} не найден.", 404);
            } catch (GitHubException $exception) {
                throw new ReviewException('GitHub: '.$exception->getMessage(), 502);
            }

            if (! $pullRequest->isOpen()) {
                throw new ReviewException("Pull request {$pullRequest->label()} уже ".($pullRequest->isMerged() ? 'слит' : 'закрыт').": {$pullRequest->url}", 409);
            }

            if ($pullRequest->head !== $base || $pullRequest->base !== $production) {
                throw new ReviewException("Pull request {$pullRequest->label()} — не релиз {$base} → {$production}.", 409);
            }

            if ($current !== $head) {
                throw new ReviewException("После запроса подтверждения в {$base} появились новые коммиты, и pull request {$pullRequest->label()} изменился: подтвердите релиз заново.", self::MOVED);
            }

            try {
                $commit = $this->github->merge($pullRequest, $head);
            } catch (GitHubException $exception) {
                throw new ReviewException("GitHub не слил {$pullRequest->label()} ({$pullRequest->url}): ".$exception->getMessage(), 409);
            }

            $git->fetch($production);
            $behind = $git->fastForward($production);

            return [
                'pullRequest' => $pullRequest,
                'commit' => substr($commit, 0, 7),
                'warnings' => $behind === null ? [] : ['Локальная ветка '.$production.' не обновлена: '.$behind],
            ];
        });
    }

    /**
     * Fetch both branches from origin; returns the commit of the development branch on GitHub.
     *
     * @throws ReviewException
     */
    private function fetch(Git $git, string $base, string $production): string
    {
        if (! $git->hasRemote()) {
            throw new ReviewException('У репозитория нет remote origin: релиз делается только через pull request на GitHub.', 409);
        }

        try {
            $this->github->ensureReady();
        } catch (GitHubException $exception) {
            throw new ReviewException($exception->getMessage(), 409);
        }

        $error = $git->fetch($base, $production);

        if ($error !== null) {
            throw new ReviewException('git fetch origin не удался: '.$error, 502);
        }

        $heads = [];

        foreach ([$production, $base] as $branch) {
            $heads[$branch] = $git->output('rev-parse', '--verify', '--quiet', 'refs/remotes/origin/'.$branch)
                ?? throw new ReviewException("На origin нет ветки {$branch}: запушьте её (git push -u origin {$branch}).", 409);
        }

        return $heads[$base];
    }

    private function body(Git $git, string $base, string $production, int $commits): string
    {
        $merges = $git->lines('log', '--first-parent', '--max-count='.self::COMMITS, '--format=- %s', 'refs/remotes/origin/'.$production.'..refs/remotes/origin/'.$base);

        return implode("\n", [
            "Выпуск `{$base}` в `{$production}`: {$commits} ".($commits === 1 ? 'коммит' : 'коммитов (с учётом слияний)').'.',
            '',
            ...$merges,
            ...($commits > count($merges) && count($merges) === self::COMMITS ? ['- …'] : []),
            '',
            '---',
            'Pull request открыт agentio по просьбе разработчика. Сливается только после его подтверждения (в Telegram-боте agentio или php artisan agentio:release --merge).',
        ]);
    }

    /**
     * Run the action under the acceptance lock of the repository, with the main checkout and both branches.
     *
     * @template TResult
     *
     * @param  Closure(Git, string, string): TResult  $action
     * @return TResult
     *
     * @throws ReviewException
     */
    private function locked(Closure $action): mixed
    {
        $git = new Git($this->settings->basePath());
        $base = $this->settings->baseBranch();
        $production = $this->settings->productionBranch();
        $result = (new RepositoryLock($git, EpicAcceptance::LOCK))->attempt(fn (): mixed => $action($git, $base, $production));

        return $result === null
            ? throw new ReviewException('Другое действие приёмки или релиза ещё выполняется: повторите позже.')
            : $result[0];
    }
}
