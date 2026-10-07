<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Install\FileChange;
use Obrazmisli\Agentio\Install\FileStatus;
use Obrazmisli\Agentio\Install\Installer;
use Obrazmisli\Agentio\Install\KnowledgeBase;
use Obrazmisli\Agentio\Install\Placeholders;
use Obrazmisli\Agentio\Install\SetupAction;
use Obrazmisli\Agentio\Install\SetupStatus;
use Obrazmisli\Agentio\Install\YouTrackSetup;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;
use Obrazmisli\Agentio\YouTrack\YouTrackException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Configures the YouTrack project for the cycle (see YouTrackSetup): Stage and Type values, tags, saved
 * searches and the knowledge base, and checks the agile board. Safe to run again at any time: what exists is
 * found and kept, nothing is created twice. The knowledge base ids are recorded in .agentio.json and in the
 * installed skills. Exits with INCOMPLETE when the rest is set up but the human has to fix something in
 * YouTrack (the board).
 */
#[AsCommand(name: 'agentio:setup-youtrack')]
final class SetupYouTrackCommand extends Command
{
    /** The exit code when everything is set up but the setup found errors only a human can fix. */
    public const int INCOMPLETE = 3;

    /**
     * @var string
     */
    protected $signature = 'agentio:setup-youtrack
        {--project= : YouTrack project short name (default: AGENTIO_PROJECT, then .agentio.json)}
        {--dry-run : Only show what would be done}';

    /**
     * @var string
     */
    protected $description = 'Configure the YouTrack project for the agent cycle (fields, tags, saved searches, knowledge base) and check its board; safe to rerun';

    public function handle(Client $client, Tools $tools, Settings $settings): int
    {
        if (! $client->isConfigured() || ! $tools->isConfigured()) {
            $this->components->error('YOUTRACK_URL and YOUTRACK_TOKEN are not set: run php artisan agentio:install first.');

            return self::FAILURE;
        }

        $project = $this->stringOption('project') ?? $settings->project();
        $dryRun = (bool) $this->option('dry-run');

        try {
            if ($tools->project($project) === null) {
                $this->components->error("The YouTrack project {$project} does not exist or the token cannot see it: create it (php artisan agentio:install offers to) and run the setup again.");

                return self::FAILURE;
            }
        } catch (YouTrackException $exception) {
            $this->components->error('Cannot read the YouTrack project: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(($dryRun ? 'Dry run, nothing is changed. Plan of the setup' : 'Setting up').' of the YouTrack project '.$project.'.');

        $manifest = $settings->manifest();
        $setup = new YouTrackSetup($client, $tools, KnowledgeBase::ofPackage(), $dryRun);
        $placeholders = new Placeholders($project, $settings->baseBranch(), $manifest->kb, $settings->productionBranch());

        try {
            $kb = $setup->run($project, $placeholders);
        } catch (YouTrackException $exception) {
            $this->renderSetup($setup->actions(), $dryRun);
            $this->components->error('YouTrack setup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->renderSetup($setup->actions(), $dryRun);

        if ($dryRun) {
            return $this->result($setup->actions());
        }

        $kb = [...$manifest->kb, ...$kb];
        $files = $manifest->files;

        if ($files !== []) {
            $installer = new Installer($settings->basePath(), dirname(__DIR__, 3).'/stubs', $placeholders->withKb($kb), false, false, $files);
            $this->renderSkillUpdates($installer->install());
            $files = $installer->hashes();
        }

        $manifest->with(project: $manifest->project ?? $project, kb: $kb, files: $files)->save($settings->basePath());
        $this->components->info('Knowledge base ids recorded in .agentio.json'.($files === [] ? '.' : ' and in the installed skills.'));

        foreach ($setup->actions() as $action) {
            if ($action->status === SetupStatus::Warning) {
                $this->components->warn("{$action->subject} {$action->name}: {$action->detail}");
            }
        }

        return $this->result($setup->actions());
    }

    /**
     * @param  list<SetupAction>  $actions
     */
    private function result(array $actions): int
    {
        $errors = array_filter($actions, fn (SetupAction $action): bool => $action->status === SetupStatus::Error);

        foreach ($errors as $action) {
            $this->components->error("{$action->subject} {$action->name}: {$action->detail}");
        }

        return $errors === [] ? self::SUCCESS : self::INCOMPLETE;
    }

    /**
     * @param  list<SetupAction>  $actions
     */
    private function renderSetup(array $actions, bool $dryRun): void
    {
        $this->table(['Status', 'Subject', 'Name', 'Detail'], array_map(fn (SetupAction $action): array => [
            $dryRun && in_array($action->status, [SetupStatus::Create, SetupStatus::Update], true) ? 'will '.$action->status->value : $action->status->value,
            $action->subject,
            $action->name,
            $action->detail,
        ], $actions));
    }

    /**
     * @param  list<FileChange>  $changes
     */
    private function renderSkillUpdates(array $changes): void
    {
        foreach ($changes as $change) {
            if ($change->status !== FileStatus::Unchanged) {
                $this->components->twoColumnDetail($change->path, $change->status->value.($change->note === '' ? '' : ' — '.$change->note));
            }
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
