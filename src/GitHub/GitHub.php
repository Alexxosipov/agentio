<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\GitHub;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The pull requests of the repository on GitHub, through the GitHub CLI (gh) the developer logged in with
 * (`gh auth login`), run in a checkout of the repository. Epics reach the development branch and releases the
 * production branch only through pull requests: agentio opens them and merges them when the developer says so.
 */
final readonly class GitHub
{
    public const int TIMEOUT = 120;

    /** The fields of a pull request PullRequest::fromJson() reads (supported by gh 2.4 and later). */
    public const string FIELDS = 'number,url,state,title,headRefName,baseRefName,isDraft,mergeable,mergeCommit';

    public function __construct(private string $directory) {}

    public function installed(): bool
    {
        return $this->succeeds('--version');
    }

    public function authenticated(): bool
    {
        return $this->succeeds('auth', 'status');
    }

    /**
     * @throws GitHubException When gh is missing or not logged in
     */
    public function ensureReady(): void
    {
        if (! $this->installed()) {
            throw GitHubException::notInstalled();
        }

        if (! $this->authenticated()) {
            throw GitHubException::notAuthenticated();
        }
    }

    /**
     * The pull requests from the head branch into the base branch, newest first: open, merged and closed.
     *
     * @return list<PullRequest>
     *
     * @throws GitHubException
     */
    public function pullRequests(string $head, string $base): array
    {
        $data = json_decode($this->run(['pr', 'list', '--head', $head, '--base', $base, '--state', 'all', '--limit', '20', '--json', self::FIELDS])->output(), true);

        return array_values(array_filter(array_map(
            fn (mixed $item): ?PullRequest => is_array($item) ? PullRequest::fromJson($item) : null,
            is_array($data) ? $data : [],
        )));
    }

    /**
     * The open pull request from head into base, else the last merged one, else null.
     *
     * @throws GitHubException
     */
    public function current(string $head, string $base): ?PullRequest
    {
        $pullRequests = $this->pullRequests($head, $base);

        foreach ([PullRequest::OPEN, PullRequest::MERGED] as $state) {
            foreach ($pullRequests as $pullRequest) {
                if ($pullRequest->state === $state) {
                    return $pullRequest;
                }
            }
        }

        return null;
    }

    /**
     * @throws GitHubException
     */
    public function find(int $number): ?PullRequest
    {
        $data = json_decode($this->run(['pr', 'view', (string) $number, '--json', self::FIELDS])->output(), true);

        return is_array($data) ? PullRequest::fromJson($data) : null;
    }

    /**
     * Open a pull request from the pushed head branch into the base branch.
     *
     * @throws GitHubException
     */
    public function create(string $head, string $base, string $title, string $body): PullRequest
    {
        $result = $this->run(['pr', 'create', '--head', $head, '--base', $base, '--title', $title, '--body-file', '-'], $body);

        foreach ($this->pullRequests($head, $base) as $pullRequest) {
            if ($pullRequest->isOpen()) {
                return $pullRequest;
            }
        }

        $url = trim($result->output());

        return preg_match('#/pull/(\d+)\s*$#', $url, $match) === 1
            ? new PullRequest((int) $match[1], $url, PullRequest::OPEN, $title, $head, $base)
            : throw new GitHubException('gh pr create did not print the URL of the pull request: '.$url);
    }

    /**
     * Merge the pull request with a merge commit, only while its head is still the commit $sha (what was checked
     * or confirmed); returns the hash of the merge commit.
     *
     * @throws GitHubException When GitHub refuses: conflicts, failed required checks, or a head that moved
     */
    public function merge(PullRequest $pullRequest, string $sha): string
    {
        $result = $this->run(['api', '--method', 'PUT', 'repos/{owner}/{repo}/pulls/'.$pullRequest->number.'/merge', '-f', 'merge_method=merge', '-f', 'sha='.$sha]);
        $data = json_decode($result->output(), true);

        return is_array($data) && is_string($data['sha'] ?? null) && ($data['merged'] ?? false) === true
            ? $data['sha']
            : throw new GitHubException('GitHub did not merge '.$pullRequest->label().': '.trim($result->output()));
    }

    /**
     * @param  list<string>  $arguments
     *
     * @throws GitHubException
     */
    private function run(array $arguments, ?string $input = null): ProcessResult
    {
        $command = 'gh '.implode(' ', array_slice($arguments, 0, 2));

        try {
            $result = $this->process($input)->run(['gh', ...$arguments]);
        } catch (Throwable $exception) {
            throw new GitHubException($command.': '.$exception->getMessage(), 0, $exception);
        }

        if ($result->exitCode() === 127) {
            throw GitHubException::notInstalled();
        }

        if ($result->failed()) {
            $error = trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : trim($result->output());

            throw new GitHubException($error !== '' ? $error : $command.' failed (exit '.($result->exitCode() ?? '?').').');
        }

        return $result;
    }

    private function succeeds(string ...$arguments): bool
    {
        try {
            return $this->process()->run(['gh', ...$arguments])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function process(?string $input = null): PendingProcess
    {
        $process = Process::path($this->directory)
            ->timeout(self::TIMEOUT)
            ->env(['GH_PROMPT_DISABLED' => '1', 'GH_NO_UPDATE_NOTIFIER' => '1', 'NO_COLOR' => '1', 'GIT_TERMINAL_PROMPT' => '0']);

        return $input === null ? $process : $process->input($input);
    }
}
