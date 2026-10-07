<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Review\EpicAcceptance;
use Obrazmisli\Agentio\Review\ReviewException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Accepts an epic in Review the way the dashboard and the Telegram bot do (EpicAcceptance): merges its pull
 * request into the development branch on GitHub (pushing the branch and opening the pull request first when
 * there is none), brings the local development branch up to date, removes the worktree and moves the reviewed
 * stories and the epic to Done. After a pull request merged on GitHub by hand it only cleans up and closes the
 * epic. Humans run it; agents never do.
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
        {--delete-branch : Delete the local epic branch after the merge}
        {--no-close : Do not move the stories and the epic to Done}
        {--json : Print the result as JSON}';

    /**
     * @var string
     */
    protected $description = 'Accept an epic in Review: merge its pull request into the development branch on GitHub and close it';

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

        if ($result['pullRequest'] !== null) {
            $this->line('  Pull request: #'.$result['pullRequest']['number'].' '.$result['pullRequest']['url']);
        }

        if ($result['closed'] !== []) {
            $this->line('  Done: '.implode(', ', $result['closed']));
        }

        foreach ($result['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
    }
}
