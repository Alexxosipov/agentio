<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Review\EpicAcceptance;
use Obrazmisli\Agentio\Review\ReviewException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Accepts an epic in Review the way the dashboard does (EpicAcceptance): merges its branch into the development
 * branch of this checkout (nothing is pushed), removes the worktree and moves the reviewed stories and the epic
 * to Done. The agent loop runs it for the auto-merge policy when the project has no remote; agents never do.
 */
#[AsCommand(name: 'agentio:accept')]
final class AcceptCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agentio:accept
        {epic : The epic id, e.g. TP-12}
        {--keep-worktree : Do not remove the epic worktree}
        {--delete-branch : Delete the epic branch after the merge}
        {--no-close : Do not move the stories and the epic to Done}
        {--json : Print the result as JSON}';

    /**
     * @var string
     */
    protected $description = 'Accept an epic in Review: merge its branch into the development branch and close it';

    public function handle(EpicAcceptance $acceptance): int
    {
        $epic = (string) $this->argument('epic');

        try {
            $result = $acceptance->accept(
                $epic,
                removeWorktree: ! $this->option('keep-worktree'),
                deleteBranch: (bool) $this->option('delete-branch'),
                close: ! $this->option('no-close'),
            );
        } catch (ReviewException $exception) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['message' => $exception->getMessage(), 'details' => $exception->details], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->components->error("{$epic}: ".$exception->getMessage());

                foreach ($exception->details as $detail) {
                    $this->line('  - '.$detail);
                }
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info($result['merged']
            ? "{$epic}: merged {$result['branch']} into {$result['base']} ({$result['commit']})."
            : "{$epic}: {$result['base']} already contains {$result['branch']}.");

        if ($result['closed'] !== []) {
            $this->line('  Done: '.implode(', ', $result['closed']));
        }

        foreach ($result['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
    }
}
