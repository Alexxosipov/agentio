<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Install\Check;
use Obrazmisli\Agentio\Install\FileChange;
use Obrazmisli\Agentio\Install\FileStatus;
use Obrazmisli\Agentio\Install\Installer;
use Obrazmisli\Agentio\Install\KnowledgeBase;
use Obrazmisli\Agentio\Install\Manifest;
use Obrazmisli\Agentio\Install\Placeholders;
use Obrazmisli\Agentio\Install\Preconditions;
use Obrazmisli\Agentio\Install\SetupAction;
use Obrazmisli\Agentio\Install\SetupStatus;
use Obrazmisli\Agentio\Install\YouTrackSetup;
use Obrazmisli\Agentio\Runtime\MergePolicy;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\YouTrackException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'agentio:install')]
final class InstallCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agentio:install
        {--project= : YouTrack project short name (default: .agentio.json, then AGENTIO_PROJECT)}
        {--base-branch= : Branch the epic branches start from (default: .agentio.json, then AGENTIO_BASE_BRANCH)}
        {--merge-policy= : local-branch, pull-request or auto-merge (default: AGENTIO_MERGE_POLICY, then CLAUDE.md)}
        {--youtrack : Also configure the YouTrack project: fields, tags, saved searches, knowledge base}
        {--force : Overwrite installed files that differ from the stubs}
        {--dry-run : Only show what would be done}';

    /**
     * @var string
     */
    protected $description = 'Install the autonomous development cycle (Claude Code agents, skills, scripts) into the project';

    public function handle(Client $client): int
    {
        $basePath = $this->laravel->basePath();
        $stubsPath = dirname(__DIR__, 3).'/stubs';
        $manifest = Manifest::load($basePath);
        $dryRun = (bool) $this->option('dry-run');

        $project = $this->stringOption('project') ?? $manifest->project ?? $this->configString('agentio.youtrack.project') ?? 'TP';
        $baseBranch = $this->stringOption('base-branch') ?? $manifest->baseBranch ?? $this->configString('agentio.base_branch') ?? 'main';
        $policy = $this->mergePolicy($basePath);

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $project) !== 1) {
            $this->components->error("Invalid YouTrack project short name: {$project}");

            return self::FAILURE;
        }

        if ($policy === null) {
            $this->components->error('Invalid merge policy: use local-branch, pull-request or auto-merge.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s agentio: YouTrack project %s, base branch %s, merge policy %s.',
            $dryRun ? 'Dry run, nothing is changed. Plan for' : 'Installing',
            $project,
            $baseBranch,
            $policy->value,
        ));

        $checks = (new Preconditions(
            $basePath,
            $this->configString('agentio.claude_binary') ?? 'claude',
            $this->configString('agentio.youtrack.url'),
            $this->configString('agentio.youtrack.token'),
        ))->checks();
        $this->renderChecks($checks);

        $placeholders = new Placeholders($project, $baseBranch, $policy, $manifest->kb);

        if ($this->option('youtrack')) {
            $kb = $this->setUpYouTrack($client, new KnowledgeBase($stubsPath), $project, $placeholders, $dryRun);

            if ($kb === null) {
                return self::FAILURE;
            }

            $placeholders = $placeholders->withKb([...$manifest->kb, ...$kb]);
        }

        $installer = new Installer($basePath, $stubsPath, $placeholders, (bool) $this->option('force'), $dryRun, $manifest->files);
        $changes = $installer->install(dirname(__DIR__, 3).'/config/agentio.php', $this->laravel->configPath('agentio.php'));
        $this->renderChanges($changes, $dryRun);

        if (! $dryRun) {
            $manifest->with($project, $baseBranch, $placeholders->kb, $installer->hashes())->save($basePath);
        }

        $this->renderNextSteps($project, $placeholders, $checks);

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>|null Knowledge base ids, or null when the setup failed
     */
    private function setUpYouTrack(Client $client, KnowledgeBase $knowledgeBase, string $project, Placeholders $placeholders, bool $dryRun): ?array
    {
        if (! $client->isConfigured()) {
            $this->components->error('--youtrack needs YOUTRACK_URL and YOUTRACK_TOKEN (config agentio.youtrack.url / agentio.youtrack.token).');

            return null;
        }

        $setup = new YouTrackSetup($client, $knowledgeBase, $dryRun);

        try {
            $kb = $setup->run($project, $placeholders);
        } catch (YouTrackException $exception) {
            $this->renderSetup($setup->actions(), $dryRun);
            $this->components->error('YouTrack setup failed: '.$exception->getMessage());

            return null;
        }

        $this->renderSetup($setup->actions(), $dryRun);

        return $kb;
    }

    /**
     * @param  list<SetupAction>  $actions
     */
    private function renderSetup(array $actions, bool $dryRun): void
    {
        $this->newLine();
        $this->line('<options=bold>YouTrack project setup</>'.($dryRun ? ' (plan)' : ''));
        $this->table(['Status', 'Subject', 'Name', 'Detail'], array_map(fn (SetupAction $action): array => [
            $dryRun && $action->status !== SetupStatus::Exists && $action->status !== SetupStatus::Warning ? 'will '.$action->status->value : $action->status->value,
            $action->subject,
            $action->name,
            $action->detail,
        ], $actions));
    }

    /**
     * @param  list<Check>  $checks
     */
    private function renderChecks(array $checks): void
    {
        $this->line('<options=bold>Preconditions</>');

        foreach ($checks as $check) {
            $this->components->twoColumnDetail(
                $check->name,
                match (true) {
                    $check->ok => '<fg=green>ok</>',
                    $check->required => '<fg=red>missing</> '.$check->hint,
                    default => '<fg=yellow>optional, missing</> '.$check->hint,
                },
            );
        }
    }

    /**
     * @param  list<FileChange>  $changes
     */
    private function renderChanges(array $changes, bool $dryRun): void
    {
        $this->newLine();
        $this->line('<options=bold>Files</>'.($dryRun ? ' (plan)' : ''));
        $this->table(['Status', 'File', 'Note'], array_map(fn (FileChange $change): array => [
            $dryRun && in_array($change->status, [FileStatus::Created, FileStatus::Updated], true) ? 'will be '.$change->status->value : $change->status->value,
            $change->path,
            $change->note,
        ], $changes));

        $counts = array_count_values(array_map(fn (FileChange $change): string => $change->status->value, $changes));
        $this->line(implode(', ', array_map(fn (string $status, int $count): string => $count.' '.$status, array_keys($counts), $counts)));

        foreach ($changes as $change) {
            if ($change->status === FileStatus::Skipped) {
                $this->components->warn("{$change->path}: {$change->note}");
            }
        }
    }

    /**
     * @param  list<Check>  $checks
     */
    private function renderNextSteps(string $project, Placeholders $placeholders, array $checks): void
    {
        $url = $this->configString('agentio.youtrack.url') ?? 'https://<instance>.youtrack.cloud';
        $steps = [];

        foreach ($checks as $check) {
            if (! $check->ok && $check->required) {
                $steps[] = "Fix: {$check->name} — {$check->hint}";
            }
        }

        if ($this->configString('agentio.youtrack.project') !== $project) {
            $steps[] = "Set AGENTIO_PROJECT={$project} in .env (the config currently says ".($this->configString('agentio.youtrack.project') ?? 'nothing').').';
        }

        if ($this->configString('agentio.base_branch') !== $placeholders->baseBranch) {
            $steps[] = "Set AGENTIO_BASE_BRANCH={$placeholders->baseBranch} in .env.";
        }

        $missingKb = array_diff(array_keys(KnowledgeBase::ARTICLES), array_keys($placeholders->kb));

        if ($missingKb !== []) {
            $steps[] = 'Configure YouTrack and record the knowledge base ids: php artisan agentio:install --youtrack --dry-run, then without --dry-run ('
                .count($missingKb).' article id(s) unknown).';
        }

        $steps[] = "Connect the YouTrack MCP server for interactive sessions: claude mcp add --transport http youtrack {$url}/mcp --header \"Authorization: Bearer <token>\"";
        $steps[] = 'Create a Kanban board in YouTrack with columns by the Stage field (optional).';
        $steps[] = "Commit the installed files to {$placeholders->baseBranch}: epic worktrees take .claude/ and scripts/ from it.";
        $steps[] = 'See what the loop would start: php artisan agentio:run --dry-run';

        if ((bool) config('agentio.ui.enabled', true)) {
            $steps[] = 'Open the dashboard: '.url($this->configString('agentio.ui.path') ?? 'agentio');
        }

        $steps[] = 'Read docs/AUTONOMOUS_WORKFLOW.md (the full manual).';

        $this->newLine();
        $this->line('<options=bold>Next steps</>');

        foreach ($steps as $index => $step) {
            $this->line(sprintf('  %d. %s', $index + 1, $step));
        }
    }

    private function mergePolicy(string $basePath): ?MergePolicy
    {
        $option = $this->stringOption('merge-policy');

        if ($option !== null) {
            return MergePolicy::tryFrom($option);
        }

        $configured = $this->configString('agentio.merge_policy');

        if ($configured !== null && MergePolicy::tryFrom($configured) === null) {
            return null;
        }

        return MergePolicy::resolve($configured, $basePath.'/CLAUDE.md');
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function configString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
