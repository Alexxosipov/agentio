<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

use Obrazmisli\Agentio\Git\Git;

/**
 * Makes sure the repository has the two long-lived branches of the cycle: the production branch (main) and the
 * development branch (dev) the epic branches start from. A missing production branch is created from HEAD, a
 * missing development branch from the production branch; both only locally (nothing is pushed, the checkout is
 * not switched). Branches the remote lacks are reported, so that a human pushes them.
 */
final readonly class BranchSetup
{
    public function __construct(private Git $git) {}

    /**
     * @return list<SetupAction>
     */
    public function ensure(string $development, string $production, bool $dryRun = false): array
    {
        if (! $this->git->hasCommits()) {
            return [new SetupAction('branch', $production, SetupStatus::Warning, 'the repository has no commits yet: commit the project and run php artisan agentio:install again')];
        }

        $actions = [
            $this->ensureBranch($production, 'HEAD', 'production', $dryRun),
        ];

        if ($development !== $production) {
            $actions[] = $this->ensureBranch($development, $production, 'development', $dryRun);
        }

        foreach ($this->missingOnRemote([$production, $development]) as $branch) {
            $actions[] = new SetupAction('branch', $branch, SetupStatus::Warning, "not on origin yet: git push -u origin {$branch}");
        }

        return $actions;
    }

    private function ensureBranch(string $name, string $from, string $role, bool $dryRun): SetupAction
    {
        if ($this->git->branchExists($name)) {
            return new SetupAction('branch', $name, SetupStatus::Exists, $role);
        }

        $detail = "{$role}, created from ".($from === 'HEAD' ? 'the current commit' : $from);

        if ($dryRun) {
            return new SetupAction('branch', $name, SetupStatus::Create, $detail);
        }

        $process = $this->git->run('branch', $name, $from);

        return $process->isSuccessful()
            ? new SetupAction('branch', $name, SetupStatus::Create, $detail)
            : new SetupAction('branch', $name, SetupStatus::Warning, 'could not be created: '.Git::error($process));
    }

    /**
     * The branches origin does not have (none when there is no origin).
     *
     * @param  list<string>  $branches
     * @return list<string>
     */
    private function missingOnRemote(array $branches): array
    {
        if ($this->git->output('remote', 'get-url', 'origin') === null) {
            return [];
        }

        return array_values(array_filter(
            array_unique($branches),
            fn (string $branch): bool => ! $this->git->run('show-ref', '--verify', '--quiet', 'refs/remotes/origin/'.$branch)->isSuccessful(),
        ));
    }
}
