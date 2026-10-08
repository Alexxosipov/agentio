<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Review\EpicPause;
use Obrazmisli\Agentio\Review\ReviewException;
use Obrazmisli\Agentio\Telegram\Messenger;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Lifts the pause of an epic (EpicPause): a claimed epic goes back In Progress and the loop of its owner resumes
 * it in the same worktree; one without a claim returns to its Stage before the pause. Humans run it; agents never do.
 */
#[AsCommand(name: 'agentio:resume')]
final class ResumeCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agentio:resume
        {epic : The epic id, e.g. TP-12}
        {--json : Print the result as JSON}';

    /**
     * @var string
     */
    protected $description = 'Lift the pause of an epic: the loop continues it where it stopped';

    public function handle(EpicPause $pause, Messenger $messenger): int
    {
        $epic = (string) $this->argument('epic');

        try {
            $result = $pause->resume($epic, 'командная строка');
        } catch (ReviewException $exception) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['message' => $exception->getMessage(), 'details' => $exception->details], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->components->error("{$epic}: ".$exception->getMessage());
            }

            return self::FAILURE;
        }

        $messenger->send(EpicPause::resumedMessage($result), 'report', $result['epic']);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info("{$result['epic']}: the pause is lifted, Stage {$result['state']}".($result['claimed'] ? " (claim of {$result['owner']}: the loop resumes it in the same worktree)." : '.'));

        return self::SUCCESS;
    }
}
