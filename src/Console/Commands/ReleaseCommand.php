<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Review\Release;
use Obrazmisli\Agentio\Review\ReviewException;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;

/**
 * A release from the command line, the way the Telegram bot makes it (Release): opens the pull request of the
 * development branch into the production branch (or finds the open one) and prints it; with --merge, merges it
 * after the developer confirms. Agents never run it.
 */
#[AsCommand(name: 'agentio:release')]
final class ReleaseCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agentio:release
        {--merge : Merge the release pull request after confirming}
        {--yes : With --merge: do not ask for the confirmation}';

    /**
     * @var string
     */
    protected $description = 'Open the pull request of a release (development branch into production) and merge it after confirmation';

    public function handle(Release $release): int
    {
        try {
            $prepared = $release->prepare();
            $pullRequest = $prepared['pullRequest'];

            $this->components->info("Release pull request {$pullRequest->label()} ({$pullRequest->head} → {$pullRequest->base}, {$prepared['commits']} commits) ".($prepared['created'] ? 'opened' : 'is open').': '.$pullRequest->url);

            if ($prepared['unpushed'] > 0) {
                $this->components->warn("The local {$pullRequest->head} has {$prepared['unpushed']} commits origin does not have: they are not part of the release.");
            }

            if (! $this->option('merge')) {
                $this->line('Check it, then merge it: php artisan agentio:release --merge');

                return self::SUCCESS;
            }

            if (! $this->option('yes') && ! confirm(label: "Merge {$pullRequest->label()} into {$pullRequest->base} (production)?", default: false)) {
                $this->components->warn('Not merged.');

                return self::FAILURE;
            }

            $merged = $release->merge($pullRequest->number, $prepared['head']);
        } catch (ReviewException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Merged {$merged['pullRequest']->label()} into {$merged['pullRequest']->base} ({$merged['commit']}).");

        foreach ($merged['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
    }
}
