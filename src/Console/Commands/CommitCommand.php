<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Console\Concerns\RunsPackageScripts;
use Obrazmisli\Agentio\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

/**
 * Commits only the given files with the "<TASK>: " prefix, under a repository-wide lock, so that subagents
 * developing tasks in one worktree never pick up each other's changes (scripts/agent-commit.sh of the package).
 */
#[AsCommand(name: 'agentio:commit')]
final class CommitCommand extends Command
{
    use RunsPackageScripts;

    public const string SCRIPT = 'agent-commit.sh';

    /**
     * @var string
     */
    protected $signature = 'agentio:commit
        {task : The task id, e.g. TP-14}
        {commit-message : What was done (gets the "<TASK>: " prefix)}
        {files* : The files of the task to commit (deleted ones too)}';

    /**
     * @var string
     */
    protected $description = 'Commit only the given files of a task (used by the agents)';

    public function handle(Settings $settings): int
    {
        /** @var list<string> $files */
        $files = (array) $this->argument('files');

        return $this->runScript(new Process(
            [...self::scriptCommand(self::SCRIPT), (string) $this->argument('task'), (string) $this->argument('commit-message'), ...$files],
            $settings->basePath(),
            $this->scriptEnvironment($settings),
            null,
            300,
        ));
    }
}
