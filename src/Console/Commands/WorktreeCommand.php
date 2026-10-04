<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Console\Concerns\RunsPackageScripts;
use Obrazmisli\Agentio\Git\Branches;
use Obrazmisli\Agentio\Git\Git;
use Obrazmisli\Agentio\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

/**
 * Creates (or reuses) the git worktree of an epic in the configured worktrees directory, on the epic's branch
 * (named after its id, started from the development branch), and prepares its environment
 * (scripts/epic-worktree.sh of the package); prints the worktree path on the last line.
 */
#[AsCommand(name: 'agentio:worktree')]
final class WorktreeCommand extends Command
{
    use RunsPackageScripts;

    public const string SCRIPT = 'epic-worktree.sh';

    /**
     * @var string
     */
    protected $signature = 'agentio:worktree
        {epic : The epic id, e.g. TP-12}
        {--remove : Remove the worktree (the branch is kept)}';

    /**
     * @var string
     */
    protected $description = 'Create and prepare the git worktree of an epic (or remove it)';

    public function handle(Settings $settings): int
    {
        if ($settings->worktreesPath() === null) {
            $this->components->error('The worktrees directory is not configured (AGENTIO_WORKTREES_PATH): run php artisan agentio:install.');

            return self::FAILURE;
        }

        $epic = (string) $this->argument('epic');

        if (preg_match(Branches::ISSUE_ID, $epic) !== 1) {
            $this->components->error("Invalid epic id: {$epic}");

            return self::INVALID;
        }

        $arguments = [$epic];

        if ($this->option('remove')) {
            $arguments[] = '--remove';
        }

        return $this->runScript(new Process(
            [self::packageScript(self::SCRIPT), ...$arguments],
            $settings->basePath(),
            $this->scriptEnvironment($settings, ['EPIC_BRANCH' => Branches::resolve(new Git($settings->basePath()), $epic)]),
            null,
            null,
        ));
    }
}
