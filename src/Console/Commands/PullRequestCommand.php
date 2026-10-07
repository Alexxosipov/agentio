<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Review\EpicPullRequest;
use Obrazmisli\Agentio\Review\ReviewException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Publishes an epic for acceptance (EpicPullRequest): pushes its branch to origin and opens the pull request into
 * the development branch, or updates the open one. The orchestrator runs it when the epic is done, the loop once
 * more when the epic reaches Review; it never merges.
 */
#[AsCommand(name: 'agentio:pr')]
final class PullRequestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agentio:pr
        {epic : The epic id, e.g. TP-12}
        {--json : Print the result as JSON}';

    /**
     * @var string
     */
    protected $description = 'Push the branch of an epic and open (or update) its pull request into the development branch';

    public function handle(EpicPullRequest $pullRequests): int
    {
        $epic = (string) $this->argument('epic');

        try {
            $result = $pullRequests->publish($epic);
        } catch (ReviewException $exception) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->components->error("{$epic}: ".$exception->getMessage());
            }

            return self::FAILURE;
        }

        $pullRequest = $result['pullRequest'];

        if ($this->option('json')) {
            $this->line((string) json_encode([...$pullRequest->toArray(), 'created' => $result['created']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info("{$epic}: pull request {$pullRequest->label()} into {$pullRequest->base} ".($result['created'] ? 'opened' : 'updated').'.');
        // The last line is the URL: the loop and the orchestrator read it.
        $this->line($pullRequest->url);

        return self::SUCCESS;
    }
}
