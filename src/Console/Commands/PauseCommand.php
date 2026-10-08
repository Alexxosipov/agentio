<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Review\EpicPause;
use Obrazmisli\Agentio\Review\ReviewException;
use Obrazmisli\Agentio\Telegram\Messenger;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Pauses an epic the way the dashboard and the Telegram bot do (EpicPause): Stage On Hold with the claim kept;
 * the session finishes its current wave of tasks, or with --now is stopped right away. Names the epics that wait
 * for it and tells the developer's Telegram bot. Humans run it; agents never do.
 */
#[AsCommand(name: 'agentio:pause')]
final class PauseCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agentio:pause
        {epic : The epic id, e.g. TP-12}
        {--now : Stop the session of the epic right away instead of after its current wave of tasks}
        {--reason= : Why, for the comment in the epic}
        {--json : Print the result as JSON}';

    /**
     * @var string
     */
    protected $description = 'Pause an epic (Stage On Hold, the claim kept): its session stops after the current wave of tasks, or now';

    public function handle(EpicPause $pause, Messenger $messenger): int
    {
        $epic = (string) $this->argument('epic');
        $reason = $this->option('reason');

        try {
            $result = $pause->pause($epic, (bool) $this->option('now'), is_string($reason) ? $reason : null, 'командная строка');
        } catch (ReviewException $exception) {
            return $this->failed($epic, $exception);
        }

        $messenger->send(EpicPause::pausedMessage($result), 'report', $result['epic']);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info(match (true) {
            $result['already'] => "{$result['epic']} is already on hold".($result['stopped'] ? '; its session was stopped now.' : '.'),
            $result['stopped'] => "{$result['epic']} is on hold; its session was stopped.",
            $result['session'] && ! $result['now'] => "{$result['epic']} is on hold; its session stops after the current wave of tasks.",
            default => "{$result['epic']} is on hold.",
        });

        if ($result['owner'] !== null) {
            $this->line('  Claim kept: '.$result['owner']);
        }

        foreach ($result['dependents'] as $dependent) {
            $this->components->warn("{$dependent['id']} ({$dependent['state']}) waits for it".($dependent['via'] === null ? '' : " through {$dependent['via']}").": {$dependent['summary']}");
        }

        $this->line("  Resume: php artisan agentio:resume {$result['epic']}");

        return self::SUCCESS;
    }

    private function failed(string $epic, ReviewException $exception): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['message' => $exception->getMessage(), 'details' => $exception->details], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->error("{$epic}: ".$exception->getMessage());
        }

        return self::FAILURE;
    }
}
