<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Install\Check;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Install\FileChange;
use Obrazmisli\Agentio\Install\FileStatus;
use Obrazmisli\Agentio\Install\Installer;
use Obrazmisli\Agentio\Install\KnowledgeBase;
use Obrazmisli\Agentio\Install\Manifest;
use Obrazmisli\Agentio\Install\Placeholders;
use Obrazmisli\Agentio\Install\Preconditions;
use Obrazmisli\Agentio\Install\SetupAction;
use Obrazmisli\Agentio\Install\SetupStatus;
use Obrazmisli\Agentio\Install\YouTrackAccess;
use Obrazmisli\Agentio\Install\YouTrackSetup;
use Obrazmisli\Agentio\Runtime\MergePolicy;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\YouTrackException;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[AsCommand(name: 'agentio:install')]
final class InstallCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agentio:install
        {--youtrack-url= : YouTrack URL, e.g. https://example.youtrack.cloud (default: YOUTRACK_URL)}
        {--token= : YouTrack permanent token (default: YOUTRACK_TOKEN; prefer the variable or the prompt)}
        {--project= : YouTrack project short name (default: .agentio.json, then AGENTIO_PROJECT)}
        {--create-project : Create the YouTrack project when it does not exist (asked when interactive)}
        {--project-name= : Name of the YouTrack project to create (default: its short name)}
        {--base-branch= : Branch the epic branches start from (default: .agentio.json, then AGENTIO_BASE_BRANCH)}
        {--merge-policy= : local-branch, pull-request or auto-merge (default: AGENTIO_MERGE_POLICY, then CLAUDE.md)}
        {--youtrack : Also configure the YouTrack project: fields, tags, saved searches, knowledge base (asked when interactive)}
        {--force : Overwrite installed files that differ from the stubs}
        {--dry-run : Only show what would be done}';

    /**
     * @var string
     */
    protected $description = 'Install the autonomous development cycle (Claude Code agents, skills, scripts) into the project';

    private const string PROJECT_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*$/';

    private const string PROJECT_EXISTS = 'exists';

    private const string PROJECT_CREATED = 'created';

    /** A dry run that would create the project. */
    private const string PROJECT_PLANNED = 'planned';

    private const string PROJECT_MISSING = 'missing';

    public function handle(Client $client): int
    {
        $basePath = $this->laravel->basePath();
        $stubsPath = dirname(__DIR__, 3).'/stubs';
        $manifest = Manifest::load($basePath);
        $dryRun = (bool) $this->option('dry-run');
        $interactive = $this->input->isInteractive();
        $env = new EnvFile($basePath.'/.env');

        $project = $this->stringOption('project') ?? $manifest->project ?? $this->configString('agentio.youtrack.project') ?? $env->get('AGENTIO_PROJECT') ?? 'TP';
        $baseBranch = $this->stringOption('base-branch') ?? $manifest->baseBranch ?? $this->configString('agentio.base_branch') ?? 'main';
        $policy = $this->mergePolicy($basePath);

        if (! $interactive && preg_match(self::PROJECT_PATTERN, $project) !== 1) {
            $this->components->error("Invalid YouTrack project short name: {$project}");

            return self::FAILURE;
        }

        if ($policy === null) {
            $this->components->error('Invalid merge policy: use local-branch, pull-request or auto-merge.');

            return self::FAILURE;
        }

        $url = $this->stringOption('youtrack-url') ?? $this->configString('agentio.youtrack.url') ?? $env->get('YOUTRACK_URL');
        $token = $this->stringOption('token') ?? $this->configString('agentio.youtrack.token') ?? $env->get('YOUTRACK_TOKEN');
        $url = $url === null ? null : rtrim($url, '/');

        $connection = $interactive ? $this->askForConnection($url, $token) : $this->checkConnection($url, $token);

        if ($connection === false) {
            return self::FAILURE;
        }

        if ($connection !== null) {
            [$url, $token] = [$connection['url'], $connection['token']];
            $client = $this->client($url, $token);
        } elseif ($interactive) {
            [$url, $token] = [null, null];
        }

        if ($interactive && $this->stringOption('project') === null) {
            $project = text(
                label: 'YouTrack project short name',
                placeholder: 'TP',
                default: preg_match(self::PROJECT_PATTERN, $project) === 1 ? $project : '',
                required: true,
                validate: fn (string $value): ?string => preg_match(self::PROJECT_PATTERN, trim($value)) === 1 ? null : 'Use letters, digits and _, starting with a letter (e.g. TP).',
                hint: 'The prefix of issue ids (TP-1); a missing project can be created.',
            );
            $project = trim($project);
        }

        $projectStatus = $connection === null ? null : $this->ensureProject(new YouTrackAccess($client), $project, $connection['user'], $interactive, $dryRun);

        if ($projectStatus === false) {
            return self::FAILURE;
        }

        $setUpYouTrack = (bool) $this->option('youtrack');

        if (! $setUpYouTrack && $interactive && $connection !== null && $projectStatus !== self::PROJECT_MISSING) {
            $setUpYouTrack = confirm(
                label: "Configure the YouTrack project {$project} now?",
                default: true,
                hint: 'Fields State / Type / Stage, tags, saved searches and the knowledge base; existing ones are kept.',
            );
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
            $url,
            $token,
        ))->checks();
        $this->renderChecks($checks);

        $placeholders = new Placeholders($project, $baseBranch, $policy, $manifest->kb);

        if ($setUpYouTrack && $projectStatus === self::PROJECT_PLANNED) {
            $this->components->warn("The YouTrack project {$project} does not exist yet, so its setup cannot be planned: run without --dry-run.");
        } elseif ($setUpYouTrack) {
            $kb = $this->setUpYouTrack($client, new KnowledgeBase($stubsPath), $project, $placeholders, $dryRun);

            if ($kb === null) {
                return self::FAILURE;
            }

            $placeholders = $placeholders->withKb([...$manifest->kb, ...$kb]);
        }

        $installer = new Installer($basePath, $stubsPath, $placeholders, (bool) $this->option('force'), $dryRun, $manifest->files);
        $changes = [
            ...$installer->install(dirname(__DIR__, 3).'/config/agentio.php', $this->laravel->configPath('agentio.php')),
            ...$installer->writeEnvironment(['YOUTRACK_URL' => $url, 'YOUTRACK_TOKEN' => $token, 'AGENTIO_PROJECT' => $project]),
        ];
        $this->renderChanges($changes, $dryRun);

        if (! $dryRun) {
            $manifest->with($project, $baseBranch, $placeholders->kb, $installer->hashes())->save($basePath);
        }

        $this->renderNextSteps($placeholders, $checks, $url !== null && $token !== null);

        return self::SUCCESS;
    }

    /**
     * Ask for the YouTrack URL and token until YouTrack accepts them (--youtrack-url and --token are tried
     * first). Returns the checked connection, false when the user gave up, null when YouTrack is skipped
     * (an empty URL).
     *
     * @return array{url: string, token: string, user: array{id: string, login: string, fullName: string}}|false|null
     */
    private function askForConnection(?string $url, ?string $token): array|false|null
    {
        $askUrl = $this->stringOption('youtrack-url') === null;
        $askToken = $this->stringOption('token') === null;

        while (true) {
            $url = ! $askUrl && $url !== null ? $url : rtrim(text(
                label: 'YouTrack URL',
                placeholder: 'https://example.youtrack.cloud',
                default: $url ?? '',
                validate: fn (string $value): ?string => trim($value) === '' || preg_match('#^https?://[^\s/]+#', trim($value)) === 1
                    ? null
                    : 'Enter the address of the YouTrack instance, e.g. https://example.youtrack.cloud.',
                hint: 'Leave empty to install without YouTrack (set it up later by running this command again).',
            ), " \t/");

            if ($url === '') {
                $this->components->warn('YouTrack is skipped: the connection is neither checked nor recorded.');

                return null;
            }

            $keep = $token !== null && (! $askToken || confirm(label: 'A YouTrack token is already set. Keep it?', default: true));

            if (! $keep) {
                $token = trim(password(
                    label: 'YouTrack permanent token',
                    placeholder: 'perm-…',
                    required: true,
                    hint: 'YouTrack → Profile → Account Security → Tokens; it is written to .env and .claude/settings.local.json only.',
                ));
            }

            /** @var string $token */
            $connection = $this->connect($url, $token);

            if ($connection !== null) {
                return $connection;
            }

            if (! confirm(label: 'Enter the YouTrack URL and token again?', default: true)) {
                return false;
            }

            [$askUrl, $askToken] = [true, true];
        }
    }

    /**
     * Without prompts: check the URL and token from the options or the environment, when both are set.
     * Returns the checked connection, false when YouTrack rejected it, null when it is not configured.
     *
     * @return array{url: string, token: string, user: array{id: string, login: string, fullName: string}}|false|null
     */
    private function checkConnection(?string $url, ?string $token): array|false|null
    {
        if ($url === null || $token === null) {
            return null;
        }

        return $this->connect($url, $token) ?? false;
    }

    /**
     * @return array{url: string, token: string, user: array{id: string, login: string, fullName: string}}|null
     */
    private function connect(string $url, string $token): ?array
    {
        try {
            $user = (new YouTrackAccess($this->client($url, $token)))->currentUser();
        } catch (YouTrackException $exception) {
            $this->components->error('Cannot access YouTrack at '.$url.': '.$exception->getMessage());

            return null;
        }

        $this->components->info(sprintf('YouTrack %s: signed in as %s (%s).', $url, $user['fullName'] !== '' ? $user['fullName'] : $user['login'], $user['login']));

        return ['url' => $url, 'token' => $token, 'user' => $user];
    }

    /**
     * Make sure the YouTrack project exists, creating it when asked to (interactively, or with --create-project).
     *
     * @param  array{id: string, login: string, fullName: string}  $user  The project leader of a new project
     * @return self::PROJECT_*|false The project status, false when YouTrack failed
     */
    private function ensureProject(YouTrackAccess $access, string $project, array $user, bool $interactive, bool $dryRun): string|false
    {
        try {
            $found = $access->project($project);
        } catch (YouTrackException $exception) {
            $this->components->error('Cannot read the YouTrack projects: '.$exception->getMessage());

            return false;
        }

        if ($found !== null) {
            return self::PROJECT_EXISTS;
        }

        if ($interactive && ! $this->option('create-project')) {
            if (! confirm(label: "The YouTrack project {$project} does not exist (or the token cannot see it). Create it?", default: true)) {
                $this->components->warn("Create the YouTrack project {$project} before starting the cycle.");

                return self::PROJECT_MISSING;
            }

            $name = $this->stringOption('project-name') ?? trim(text(label: 'Name of the new YouTrack project', default: $project, required: true));
        } elseif ($this->option('create-project')) {
            $name = $this->stringOption('project-name') ?? $project;
        } else {
            $this->components->warn("The YouTrack project {$project} does not exist or the token cannot see it: create it in YouTrack, or pass --create-project (and --project-name).");

            return self::PROJECT_MISSING;
        }

        if ($dryRun) {
            $this->components->info("Would create the YouTrack project {$project} «{$name}» led by {$user['login']}.");

            return self::PROJECT_PLANNED;
        }

        try {
            $access->createProject($project, $name, $user['id']);
        } catch (YouTrackException $exception) {
            $this->components->error("Cannot create the YouTrack project {$project}: ".$exception->getMessage().' (the token needs the permission to create projects).');

            return false;
        }

        $this->components->info("Created the YouTrack project {$project} «{$name}» led by {$user['login']}.");

        return self::PROJECT_CREATED;
    }

    private function client(string $url, string $token): Client
    {
        return new Client(
            url: $url,
            token: $token,
            timeout: (int) config('agentio.youtrack.timeout', 30),
            retries: (int) config('agentio.youtrack.retries', 2),
        );
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
            $dryRun && in_array($change->status, [FileStatus::Created, FileStatus::Updated, FileStatus::Removed], true) ? 'will be '.$change->status->value : $change->status->value,
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
    private function renderNextSteps(Placeholders $placeholders, array $checks, bool $connected): void
    {
        $steps = [];

        foreach ($checks as $check) {
            if (! $check->ok && $check->required) {
                $steps[] = "Fix: {$check->name} — {$check->hint}";
            }
        }

        $configuredBranch = $this->configString('agentio.base_branch');

        if ($configuredBranch !== null && $configuredBranch !== $placeholders->baseBranch) {
            $steps[] = "Set AGENTIO_BASE_BRANCH={$placeholders->baseBranch} in .env or remove it (the config says {$configuredBranch}).";
        }

        $missingKb = array_diff(array_keys(KnowledgeBase::ARTICLES), array_keys($placeholders->kb));

        if ($missingKb !== []) {
            $steps[] = 'Configure YouTrack and record the knowledge base ids: php artisan agentio:install --youtrack --dry-run, then without --dry-run ('
                .count($missingKb).' article id(s) unknown).';
        }

        $steps[] = $connected
            ? 'Start `claude` in the project and trust the folder: the youtrack MCP server of .mcp.json takes YOUTRACK_URL and YOUTRACK_TOKEN from .claude/settings.local.json'
                .' (`claude -p` and `claude mcp get|list` do not read it: export the variables for them).'
            : 'Connect YouTrack: run php artisan agentio:install again and enter the URL and the token (they go to .env and .claude/settings.local.json for the MCP server).';
        $steps[] = 'Create a Kanban board in YouTrack with columns by the Stage field (optional).';
        $steps[] = "Commit the installed files to {$placeholders->baseBranch} (.claude/settings.local.json and .env stay local): epic worktrees take .claude/ and scripts/ from it.";
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
