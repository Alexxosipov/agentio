<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Tests\Fakes;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * A stateful fake of the GitHub CLI (gh) over a real bare repository that plays origin: pull requests are listed,
 * viewed and opened like gh does it, and merged into the bare repository for real (a merge commit, as GitHub's
 * "Create a merge commit"), refusing a conflict or a head that moved. Registered with Process::fake() for the
 * commands starting with gh; every call is recorded.
 */
final class FakeGitHub
{
    public const string OWNER = 'acme';

    public bool $installed = true;

    public bool $authenticated = true;

    /** @var array<int, array{number: int, url: string, state: string, title: string, headRefName: string, baseRefName: string, isDraft: bool, mergeable: string, mergeCommit: array{oid: string}|null, body: string}> */
    public array $pullRequests = [];

    /** @var list<list<string>> */
    public array $calls = [];

    public function __construct(public string $origin) {}

    /**
     * A bare repository as origin of the repository in $directory, with the given branches pushed.
     */
    public static function originOf(string $directory, string ...$branches): self
    {
        $origin = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agentio-origin-'.Str::random(12).'.git';
        self::git(dirname($origin), 'init', '-q', '--bare', $origin);
        test()->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($origin));

        self::git($directory, 'remote', 'add', 'origin', $origin);

        foreach ($branches as $branch) {
            self::git($directory, 'push', '-q', 'origin', 'refs/heads/'.$branch.':refs/heads/'.$branch);
        }

        return new self($origin);
    }

    public function fake(): self
    {
        Process::fake(["'gh' *" => fn (PendingProcess $process): ProcessResult => $this->handle($process)]);

        return $this;
    }

    /**
     * Seed a pull request.
     */
    public function pullRequest(string $head, string $base, string $state = 'OPEN', bool $draft = false, string $mergeable = 'MERGEABLE', string $title = 'Pull request'): int
    {
        $number = count($this->pullRequests) + 1;
        $this->pullRequests[$number] = [
            'number' => $number,
            'url' => 'https://github.com/'.self::OWNER.'/app/pull/'.$number,
            'state' => $state,
            'title' => $title,
            'headRefName' => $head,
            'baseRefName' => $base,
            'isDraft' => $draft,
            'mergeable' => $mergeable,
            'mergeCommit' => null,
            'body' => '',
        ];

        return $number;
    }

    /**
     * The calls of a subcommand, e.g. "pr create" or "api".
     *
     * @return list<list<string>>
     */
    public function callsOf(string $command): array
    {
        $words = explode(' ', $command);

        return array_values(array_filter($this->calls, fn (array $call): bool => array_slice($call, 0, count($words)) === $words));
    }

    /**
     * The commit of a branch of origin.
     */
    public function head(string $branch): ?string
    {
        $process = new SymfonyProcess(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch], $this->origin);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    /**
     * The subject of the last commit of a branch of origin.
     */
    public function subject(string $branch): string
    {
        return self::git($this->origin, 'log', '-1', '--format=%s', 'refs/heads/'.$branch);
    }

    private function handle(PendingProcess $process): ProcessResult
    {
        $arguments = array_values(array_slice((array) $process->command, 1));
        $this->calls[] = $arguments;

        return match (true) {
            $arguments === ['--version'] => $this->installed ? self::ok("gh version 2.40.0 (2023-12-07)\n") : self::failure('', 127),
            $arguments === ['auth', 'status'] => $this->authenticated ? self::ok('') : self::failure("You are not logged into any GitHub hosts. Run gh auth login to authenticate.\n"),
            ! $this->installed => self::failure('', 127),
            ! $this->authenticated => self::failure("gh: To get started with GitHub CLI, please run:  gh auth login\n", 4),
            array_slice($arguments, 0, 2) === ['pr', 'list'] => $this->list($arguments),
            array_slice($arguments, 0, 2) === ['pr', 'view'] => isset($this->pullRequests[(int) $arguments[2]])
                ? self::ok((string) json_encode($this->public($this->pullRequests[(int) $arguments[2]])))
                : self::failure("GraphQL: Could not resolve to a PullRequest with the number of {$arguments[2]}.\n"),
            array_slice($arguments, 0, 2) === ['pr', 'create'] => $this->create($arguments, is_string($process->input) ? $process->input : ''),
            ($arguments[0] ?? '') === 'api' => $this->merge($arguments),
            default => self::failure('unknown command: gh '.implode(' ', $arguments)."\n"),
        };
    }

    /**
     * @param  list<string>  $arguments
     */
    private function list(array $arguments): ProcessResult
    {
        $head = self::option($arguments, '--head');
        $base = self::option($arguments, '--base');
        $matching = array_filter($this->pullRequests, fn (array $pull): bool => ($head === null || $pull['headRefName'] === $head) && ($base === null || $pull['baseRefName'] === $base));
        krsort($matching);

        return self::ok((string) json_encode(array_values(array_map($this->public(...), $matching))));
    }

    /**
     * @param  list<string>  $arguments
     */
    private function create(array $arguments, string $body): ProcessResult
    {
        $head = (string) self::option($arguments, '--head');
        $base = (string) self::option($arguments, '--base');

        if ($this->head($head) === null) {
            return self::failure("pull request create failed: GraphQL: Head sha can't be blank, Base sha can't be blank, No commits between {$base} and {$head}, Head ref must be a branch (createPullRequest)\n");
        }

        foreach ($this->pullRequests as $pull) {
            if ($pull['headRefName'] === $head && $pull['baseRefName'] === $base && $pull['state'] === 'OPEN') {
                return self::failure("a pull request for branch \"{$head}\" into branch \"{$base}\" already exists:\n{$pull['url']}\n");
            }
        }

        $number = $this->pullRequest($head, $base, title: (string) self::option($arguments, '--title'));
        $this->pullRequests[$number]['body'] = $body;

        return self::ok($this->pullRequests[$number]['url']."\n");
    }

    /**
     * gh api --method PUT repos/{owner}/{repo}/pulls/<n>/merge -f merge_method=merge -f sha=<sha>
     *
     * @param  list<string>  $arguments
     */
    private function merge(array $arguments): ProcessResult
    {
        $path = (string) (array_values(array_filter($arguments, fn (string $argument): bool => str_starts_with($argument, 'repos/')))[0] ?? '');

        if (preg_match('#^repos/\{owner\}/\{repo\}/pulls/(\d+)/merge$#', $path, $match) !== 1 || ! in_array('merge_method=merge', $arguments, true)) {
            return self::failure('unknown API call: gh '.implode(' ', $arguments)."\n");
        }

        $pull = $this->pullRequests[(int) $match[1]] ?? null;
        $sha = substr((string) (array_values(array_filter($arguments, fn (string $argument): bool => str_starts_with($argument, 'sha=')))[0] ?? ''), 4);

        if ($pull === null) {
            return self::failure("gh: Not Found (HTTP 404)\n");
        }

        if ($pull['state'] !== 'OPEN' || $pull['mergeable'] === 'CONFLICTING') {
            return self::failure("gh: Pull Request is not mergeable (HTTP 405)\n");
        }

        if ($sha !== '' && $sha !== $this->head($pull['headRefName'])) {
            return self::failure("gh: Head branch was modified. Review and try the merge again. (HTTP 409)\n");
        }

        $clone = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agentio-merge-'.Str::random(12);

        try {
            self::git(dirname($clone), 'clone', '-q', $this->origin, $clone);

            foreach (['user.email' => 'github@example.com', 'user.name' => 'GitHub', 'commit.gpgsign' => 'false'] as $key => $value) {
                self::git($clone, 'config', $key, $value);
            }

            self::git($clone, 'checkout', '-q', $pull['baseRefName']);
            $merge = new SymfonyProcess(['git', 'merge', '--no-ff', '-q', '-m', "Merge pull request #{$pull['number']} from ".self::OWNER."/{$pull['headRefName']}", 'origin/'.$pull['headRefName']], $clone);
            $merge->run();

            if (! $merge->isSuccessful()) {
                $this->pullRequests[$pull['number']]['mergeable'] = 'CONFLICTING';

                return self::failure("gh: Pull Request is not mergeable (HTTP 405)\n");
            }

            self::git($clone, 'push', '-q', 'origin', $pull['baseRefName']);
            $commit = self::git($clone, 'rev-parse', 'HEAD');
        } finally {
            (new Filesystem)->deleteDirectory($clone);
        }

        $this->pullRequests[$pull['number']]['state'] = 'MERGED';
        $this->pullRequests[$pull['number']]['mergeCommit'] = ['oid' => $commit];

        return self::ok((string) json_encode(['sha' => $commit, 'merged' => true, 'message' => 'Pull Request successfully merged']));
    }

    /**
     * @param  array<string, mixed>  $pull
     * @return array<string, mixed>
     */
    private function public(array $pull): array
    {
        unset($pull['body']);

        return $pull;
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function option(array $arguments, string $name): ?string
    {
        $index = array_search($name, $arguments, true);

        return $index === false ? null : ($arguments[$index + 1] ?? null);
    }

    private static function ok(string $output): ProcessResult
    {
        return Process::result($output)->withCommand('gh');
    }

    private static function failure(string $error, int $exitCode = 1): ProcessResult
    {
        return Process::result('', $error, $exitCode)->withCommand('gh');
    }

    private static function git(string $directory, string ...$arguments): string
    {
        $process = new SymfonyProcess(['git', ...$arguments], $directory);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
