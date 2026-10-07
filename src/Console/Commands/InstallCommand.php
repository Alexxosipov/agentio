<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Git\Git;
use Obrazmisli\Agentio\Install\BranchSetup;
use Obrazmisli\Agentio\Install\Check;
use Obrazmisli\Agentio\Install\ClaudeMcp;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Install\FileChange;
use Obrazmisli\Agentio\Install\FileStatus;
use Obrazmisli\Agentio\Install\Installer;
use Obrazmisli\Agentio\Install\KnowledgeBase;
use Obrazmisli\Agentio\Install\LocalServices;
use Obrazmisli\Agentio\Install\Manifest;
use Obrazmisli\Agentio\Install\Placeholders;
use Obrazmisli\Agentio\Install\Preconditions;
use Obrazmisli\Agentio\Install\SetupAction;
use Obrazmisli\Agentio\Install\SetupStatus;
use Obrazmisli\Agentio\Install\YouTrackAccess;
use Obrazmisli\Agentio\Install\YouTrackSetup;
use Obrazmisli\Agentio\Settings;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\IssueRepository;
use Obrazmisli\Agentio\YouTrack\Mcp\McpClient;
use Obrazmisli\Agentio\YouTrack\Mcp\Tools;
use Obrazmisli\Agentio\YouTrack\YouTrackException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Installs the agent cycle into the project: checks first that the GitHub CLI is installed and logged in (epics
 * are accepted and releases made only through pull requests; without it nothing is installed), asks for the
 * YouTrack connection and checks it through the MCP server, adds the youtrack MCP server to Claude Code when it
 * has none, asks for the YouTrack project, the development and the production branch (creating the missing ones
 * locally) and the directory of the epic worktrees, optionally configures the YouTrack project
 * (agentio:setup-youtrack), installs the skills into .claude/skills and the local services of docker compose
 * (PostgreSQL with the development and the testing database, Redis: compose.yaml, .env, .env.example and
 * phpunit.xml point at them) — the only files it adds to the project besides .agentio.json; the scripts and the
 * manual stay in the package — and offers the developer's Telegram bot (agentio:setup-telegram).
 */
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
        {--base-branch= : Development branch (develop server): epic branches start from it and reach it through pull requests (default: .agentio.json, then dev)}
        {--production-branch= : Production branch: releases of the development branch reach it through a pull request (default: .agentio.json, then main)}
        {--worktrees= : Directory of the epic worktrees, outside the project (default: AGENTIO_WORKTREES_PATH; asked when interactive)}
        {--mcp-scope=local : Scope of the youtrack MCP server added to Claude Code: local (this project, private) or user}
        {--setup-youtrack : Also configure the YouTrack project (agentio:setup-youtrack; asked when interactive)}
        {--telegram-token= : Set up the developer\'s Telegram bot with this token (agentio:setup-telegram; asked when interactive; prefer the prompt: arguments stay in the shell history)}
        {--skip-telegram : Do not ask about the Telegram bot}
        {--skip-services : Do not add the docker compose services (PostgreSQL, Redis) nor point .env and phpunit.xml at them}
        {--force : Overwrite installed skills that were edited locally}
        {--dry-run : Only show what would be done}';

    /**
     * @var string
     */
    protected $description = 'Install the agent cycle into the project: YouTrack connection and MCP server, project settings, skills';

    private const string PROJECT_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*$/';

    private bool $interactive = false;

    private bool $dryRun = false;

    public function handle(Settings $settings): int
    {
        $basePath = $settings->basePath();
        $manifest = $settings->manifest();
        $env = new EnvFile($basePath.'/.env');
        $this->interactive = $this->input->isInteractive();
        $this->dryRun = (bool) $this->option('dry-run');

        $this->components->info(($this->dryRun ? 'Dry run, nothing is changed. Plan of the agentio installation' : 'Installing agentio').' into '.$basePath.'.');

        $checks = (new Preconditions($basePath, $settings->claudeBinary()))->checks();
        $this->renderChecks($checks);

        foreach ($checks as $check) {
            if (! $check->ok && $check->blocking) {
                $this->components->error("{$check->name}: {$check->hint}. agentio:install does not continue without it.");

                return self::FAILURE;
            }
        }

        $scope = (string) $this->option('mcp-scope');

        if (! in_array($scope, ClaudeMcp::SCOPES, true)) {
            $this->components->error('Invalid --mcp-scope: use '.implode(' or ', ClaudeMcp::SCOPES).'.');

            return self::FAILURE;
        }

        // 1. The YouTrack connection, checked through the MCP server.
        $url = $this->stringOption('youtrack-url') ?? Settings::string('agentio.youtrack.url') ?? $env->get('YOUTRACK_URL');
        $token = $this->stringOption('token') ?? Settings::string('agentio.youtrack.token') ?? $env->get('YOUTRACK_TOKEN');
        $connection = $this->interactive ? $this->askForConnection($url === null ? null : rtrim($url, '/'), $token) : $this->checkConnection($url, $token);

        if ($connection === false) {
            return self::FAILURE;
        }

        // 2. The youtrack MCP server of Claude Code.
        if ($connection !== null) {
            $this->registerMcpServer($basePath, $connection['url'], $connection['token'], $connection['tokenChanged'], $scope);
        }

        // 3. The project settings.
        $project = $this->askProject($this->stringOption('project') ?? $manifest->project ?? Settings::string('agentio.youtrack.project') ?? 'TP');

        if ($project === null) {
            return self::FAILURE;
        }

        $projectExists = null;

        if ($connection !== null) {
            $projectExists = $this->ensureProject($connection, $project);

            if ($projectExists === false) {
                return self::FAILURE;
            }
        }

        $git = new Git($basePath);
        $productionBranch = $this->askBranch('production-branch', 'Production branch', 'What production runs; a release of the development branch reaches it through a pull request you confirm.', $this->stringOption('production-branch') ?? $manifest->productionBranch ?? Settings::string('agentio.production_branch') ?? $this->defaultProductionBranch($git));
        $baseBranch = $this->askBranch('base-branch', 'Development branch', 'What the develop server runs; every epic gets a branch named after its id (TP-12), started from it and merged back into it through a pull request. Agents never push to it.', $this->stringOption('base-branch') ?? $manifest->baseBranch ?? Settings::string('agentio.base_branch') ?? Settings::DEVELOPMENT_BRANCH);

        if ($baseBranch === null || $productionBranch === null) {
            return self::FAILURE;
        }

        $worktrees = $this->askWorktrees($basePath, $this->stringOption('worktrees') ?? $settings->worktreesPath());

        if ($worktrees === null) {
            return self::FAILURE;
        }

        // 4. .env (the connection and the machine settings) and .agentio.json (the project settings).
        $environment = [
            'YOUTRACK_URL' => $connection['url'] ?? null,
            'YOUTRACK_TOKEN' => $connection['token'] ?? null,
            'AGENTIO_WORKTREES_PATH' => $worktrees,
        ];

        foreach (['AGENTIO_PROJECT' => $project, 'AGENTIO_BASE_BRANCH' => $baseBranch, 'AGENTIO_PRODUCTION_BRANCH' => $productionBranch] as $key => $value) {
            if ($env->get($key) !== null) {
                $environment[$key] = $value;
            }
        }

        // The local services: PostgreSQL (development and testing database) and Redis in docker compose.
        $services = new LocalServices($basePath);
        $withServices = $this->withServices($services);

        if ($withServices) {
            $environment = [...$environment, ...$services->environment()];
        }

        $installer = new Installer($basePath, dirname(__DIR__, 3).'/stubs', new Placeholders($project, $baseBranch, productionBranch: $productionBranch), (bool) $this->option('force'), $this->dryRun, $manifest->files);
        $envChange = $installer->writeEnvironment($environment);
        $serviceChanges = $withServices ? [
            is_file($basePath.'/.env.example') ? $installer->writeEnvironment($services->environment('.env.example'), '.env.example') : null,
            $services->configurePhpUnit($this->dryRun),
        ] : [];
        $this->useSettings($connection, $project, $baseBranch, $productionBranch, $worktrees);
        $this->renderBranches((new BranchSetup($git))->ensure($baseBranch, $productionBranch, $this->dryRun));

        if (! $this->dryRun) {
            $manifest->with($project, $baseBranch, productionBranch: $productionBranch)->save($basePath);

            if (! is_dir($worktrees)) {
                mkdir($worktrees, 0755, true);
            }
        }

        // 5. The YouTrack project setup (records the knowledge base ids in .agentio.json).
        $setup = null;

        if ($connection !== null && $projectExists === true && $this->shouldSetUpYouTrack($project)) {
            $this->newLine();

            $setup = $this->call('agentio:setup-youtrack', ['--project' => $project, '--dry-run' => $this->dryRun]);

            // INCOMPLETE: the rest of the project is set up, so the skills are installed; the run fails at the end.
            if ($setup !== self::SUCCESS && $setup !== SetupYouTrackCommand::INCOMPLETE) {
                return self::FAILURE;
            }
        }

        // 6. The skills.
        $kb = Manifest::load($basePath)->kb;
        $installer = new Installer($basePath, dirname(__DIR__, 3).'/stubs', new Placeholders($project, $baseBranch, $kb, $productionBranch), (bool) $this->option('force'), $this->dryRun, Manifest::load($basePath)->files, $withServices);
        $changes = $installer->install();
        $this->renderChanges(array_values(array_filter([...$changes, $envChange, ...$serviceChanges])));

        if (! $this->dryRun) {
            Manifest::load($basePath)->with($project, $baseBranch, $kb, $installer->hashes(), $productionBranch)->save($basePath);
        }

        // 7. The developer's Telegram bot (optional).
        $telegram = $this->setUpTelegram();

        $this->renderNextSteps($checks, $baseBranch, $kb, $connection !== null, $setup, $withServices);

        if ($telegram === false) {
            $this->components->warn('The Telegram bot is not set up (see above): run php artisan agentio:setup-telegram when it is fixed.');
        }

        return $setup === SetupYouTrackCommand::INCOMPLETE ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Ask for the YouTrack URL and token until the MCP server accepts them (--youtrack-url and --token are
     * tried first). Returns the checked connection, false when the user gave up, null when YouTrack is skipped
     * (an empty URL).
     *
     * @return array{url: string, token: string, tokenChanged: bool, user: array{login: string, name: string, email: string}}|false|null
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
                hint: 'Leave empty to install without YouTrack (connect it later by running this command again).',
            ), " \t/");

            if ($url === '') {
                $this->components->warn('YouTrack is skipped: the connection is neither checked nor recorded, no MCP server is added.');

                return null;
            }

            $previous = $token;
            $keep = $token !== null && (! $askToken || confirm(label: 'A YouTrack token is already set (environment or .env). Keep it?', default: true));

            if (! $keep) {
                $token = trim(password(
                    label: 'YouTrack permanent token',
                    placeholder: 'perm-…',
                    required: true,
                    hint: 'YouTrack → Profile → Account Security → Tokens. It goes to .env (git-ignored) and to the youtrack MCP server of Claude Code.',
                ));
            }

            /** @var string $token */
            $connection = $this->connect($url, $token, $previous !== null && $previous !== $token);

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
     * Without prompts: check the URL and token of the options or the environment, when both are set.
     * Returns the checked connection, false when YouTrack rejected it, null when it is not configured.
     *
     * @return array{url: string, token: string, tokenChanged: bool, user: array{login: string, name: string, email: string}}|false|null
     */
    private function checkConnection(?string $url, ?string $token): array|false|null
    {
        if ($url === null || $token === null) {
            $this->components->warn('YOUTRACK_URL / YOUTRACK_TOKEN are not set: YouTrack is skipped (pass --youtrack-url and set YOUTRACK_TOKEN).');

            return null;
        }

        $configured = Settings::string('agentio.youtrack.token');

        return $this->connect(rtrim($url, '/'), $token, $configured !== null && $configured !== $token) ?? false;
    }

    /**
     * @return array{url: string, token: string, tokenChanged: bool, user: array{login: string, name: string, email: string}}|null
     */
    private function connect(string $url, string $token, bool $tokenChanged): ?array
    {
        try {
            $user = $this->tools($url, $token)->currentUser();
        } catch (YouTrackException $exception) {
            $this->components->error('Cannot reach the YouTrack MCP server at '.$url.'/mcp: '.$exception->getMessage());

            return null;
        }

        $this->components->info(sprintf('YouTrack %s (MCP): signed in as %s (%s). The agents take only the issues %s reported (created).', $url, $user['name'] !== '' ? $user['name'] : $user['login'], $user['login'], $user['login']));

        return ['url' => $url, 'token' => $token, 'tokenChanged' => $tokenChanged, 'user' => $user];
    }

    /**
     * Add the youtrack MCP server to Claude Code when it has none for the project (or re-add it in the chosen
     * scope after the token changed).
     */
    private function registerMcpServer(string $basePath, string $url, string $token, bool $tokenChanged, string $scope): void
    {
        $claude = $this->laravel->make(Settings::class)->claudeBinary();

        if (Preconditions::executable($claude) === null) {
            $this->components->warn("Claude Code CLI not found ({$claude}): add the MCP server later with php artisan agentio:install.");

            return;
        }

        $mcp = new ClaudeMcp($claude, $basePath);
        $exists = $mcp->has();

        if ($exists && ! $tokenChanged) {
            $this->components->info('Claude Code already has the youtrack MCP server for this project: kept.');

            return;
        }

        if ($this->dryRun) {
            $this->components->info(($exists ? 'Would replace' : 'Would add')." the youtrack MCP server ({$url}/mcp, scope {$scope}) in Claude Code.");

            return;
        }

        try {
            if ($exists) {
                $mcp->remove($scope);
            }

            $mcp->add($url, $token, $scope);
        } catch (RuntimeException $exception) {
            $this->components->warn($exception->getMessage().' — add it by hand: claude mcp add --transport http youtrack '.$url.'/mcp --header "Authorization: Bearer <token>"');

            return;
        }

        $this->components->info(($exists ? 'Replaced' : 'Added')." the youtrack MCP server in Claude Code ({$url}/mcp, scope {$scope}; the token is stored by Claude Code, not in the project).");
    }

    private function askProject(string $default): ?string
    {
        if (! $this->interactive || $this->stringOption('project') !== null) {
            if (preg_match(self::PROJECT_PATTERN, $default) !== 1) {
                $this->components->error("Invalid YouTrack project short name: {$default}");

                return null;
            }

            return $default;
        }

        return trim(text(
            label: 'YouTrack project short name',
            placeholder: 'TP',
            default: preg_match(self::PROJECT_PATTERN, $default) === 1 ? $default : '',
            required: true,
            validate: fn (string $value): ?string => preg_match(self::PROJECT_PATTERN, trim($value)) === 1 ? null : 'Use letters, digits and _, starting with a letter (e.g. TP).',
            hint: 'The prefix of issue ids (TP-1); a missing project can be created.',
        ));
    }

    /**
     * Make sure the YouTrack project exists (found through the MCP server), creating it when asked to; the REST
     * API is used for the creation only, the MCP server has no tool for it.
     *
     * @param  array{url: string, token: string, tokenChanged: bool, user: array{login: string, name: string, email: string}}  $connection
     * @return bool|null True when it exists (or was created), null when it is missing, false when YouTrack failed
     */
    private function ensureProject(array $connection, string $project): ?bool
    {
        try {
            if ($this->tools($connection['url'], $connection['token'])->project($project) !== null) {
                return true;
            }
        } catch (YouTrackException $exception) {
            $this->components->error('Cannot read the YouTrack projects: '.$exception->getMessage());

            return false;
        }

        if ($this->interactive && ! $this->option('create-project')) {
            if (! confirm(label: "The YouTrack project {$project} does not exist (or the token cannot see it). Create it?", default: true)) {
                $this->components->warn("Create the YouTrack project {$project} before starting the cycle, then run php artisan agentio:setup-youtrack.");

                return null;
            }

            $name = $this->stringOption('project-name') ?? trim(text(label: 'Name of the new YouTrack project', default: $project, required: true));
        } elseif ($this->option('create-project')) {
            $name = $this->stringOption('project-name') ?? $project;
        } else {
            $this->components->warn("The YouTrack project {$project} does not exist or the token cannot see it: create it in YouTrack, or pass --create-project (and --project-name).");

            return null;
        }

        if ($this->dryRun) {
            $this->components->info("Would create the YouTrack project {$project} «{$name}» led by {$connection['user']['login']}.");

            return null;
        }

        $access = new YouTrackAccess(new Client($connection['url'], $connection['token'], (int) config('agentio.youtrack.timeout', 30), (int) config('agentio.youtrack.retries', 2)));

        try {
            $access->createProject($project, $name, $access->currentUser()['id']);
        } catch (YouTrackException $exception) {
            $this->components->error("Cannot create the YouTrack project {$project}: ".$exception->getMessage().' (the token needs the permission to create projects).');

            return false;
        }

        $this->components->info("Created the YouTrack project {$project} «{$name}» led by {$connection['user']['login']}.");

        return true;
    }

    /**
     * The development or the production branch: asked, or taken from its option; null when it is not a valid
     * branch name.
     */
    private function askBranch(string $option, string $label, string $hint, string $default): ?string
    {
        $invalid = fn (string $name): ?string => self::validBranchName(trim($name)) ? null : 'Not a valid branch name.';

        if ($this->interactive && $this->stringOption($option) === null) {
            return trim(text(label: $label, default: $default, required: true, validate: $invalid, hint: $hint));
        }

        if ($invalid($default) !== null) {
            $this->components->error("--{$option}: {$default} is not a valid branch name.");

            return null;
        }

        return $default;
    }

    /**
     * The production branch of a repository installed for the first time: main, else master, else the current branch.
     */
    private function defaultProductionBranch(Git $git): string
    {
        foreach ([Settings::PRODUCTION_BRANCH, 'master'] as $branch) {
            if ($git->branchExists($branch)) {
                return $branch;
            }
        }

        return $git->currentBranch() ?? Settings::PRODUCTION_BRANCH;
    }

    private static function validBranchName(string $name): bool
    {
        return preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $name) === 1
            && ! str_contains($name, '..')
            && ! str_ends_with($name, '/')
            && ! str_ends_with($name, '.lock');
    }

    /**
     * The directory of the epic worktrees (absolute, outside the project): asked, or taken from --worktrees or
     * AGENTIO_WORKTREES_PATH. There is no silent default: the user chooses it.
     */
    private function askWorktrees(string $basePath, ?string $current): ?string
    {
        $absolute = fn (string $path): string => $this->absolutePath($basePath, $path);
        $invalid = fn (string $path): ?string => match (true) {
            trim($path) === '' => 'Enter a directory.',
            $absolute($path) === rtrim($basePath, '/') || str_starts_with($absolute($path).'/', rtrim($basePath, '/').'/') => 'Choose a directory outside the project: worktrees inside it would show up in git status.',
            default => null,
        };

        if (! $this->interactive || $this->stringOption('worktrees') !== null) {
            if ($current === null) {
                $this->components->error('Choose where the epic worktrees live: pass --worktrees=<directory outside the project> (or set AGENTIO_WORKTREES_PATH).');

                return null;
            }

            $error = $invalid($current);

            if ($error !== null) {
                $this->components->error('--worktrees: '.$error);

                return null;
            }

            return $absolute($current);
        }

        return $absolute(trim(text(
            label: 'Directory of the epic worktrees',
            default: $current ?? dirname(rtrim($basePath, '/')).'/'.basename(rtrim($basePath, '/')).'-worktrees',
            required: true,
            validate: $invalid,
            hint: 'Every epic gets its own git worktree <directory>/<EPIC-ID>; outside the project. Written to .env (AGENTIO_WORKTREES_PATH).',
        )));
    }

    /**
     * Whether to install the local services: not with --skip-services, and only for a project on SQLite or
     * PostgreSQL (another database is left alone, with a warning).
     */
    private function withServices(LocalServices $services): bool
    {
        if ($this->option('skip-services')) {
            return false;
        }

        if (! $services->supported()) {
            $this->components->warn("The project uses the {$services->connection()} database: the docker compose services (PostgreSQL, Redis) are not added and .env and phpunit.xml are kept (--skip-services hides this).");

            return false;
        }

        return true;
    }

    private function shouldSetUpYouTrack(string $project): bool
    {
        if ($this->option('setup-youtrack')) {
            return true;
        }

        return $this->interactive && confirm(
            label: "Configure the YouTrack project {$project} now?",
            default: true,
            hint: 'Stage and Type values, tags, saved searches and the knowledge base (agentio:setup-youtrack, safe to rerun).',
        );
    }

    /**
     * Ask whether the developer wants the Telegram bot and set it up (agentio:setup-telegram: the token in .env,
     * voice messages, Horizon, the chat; it restarts whatever uses an old token). Returns null when skipped,
     * otherwise whether the setup succeeded.
     */
    private function setUpTelegram(): ?bool
    {
        $token = $this->stringOption('telegram-token');

        if ($this->dryRun || $this->option('skip-telegram') || ($token === null && ! $this->interactive)) {
            return null;
        }

        if ($token === null) {
            $configured = Settings::string('agentio.telegram.token') !== null;
            $this->newLine();

            $wanted = confirm(
                label: $configured ? 'The Telegram bot is set up. Set it up again (token, voice messages, Horizon)?' : 'Connect your own Telegram bot (questions of the agents, short reports, ideas by voice)?',
                default: false,
                hint: 'A bot you create with @BotFather; its token goes to .env as AGENTIO_TELEGRAM_BOT_TOKEN. Later: php artisan agentio:setup-telegram.',
            );

            if (! $wanted) {
                return null;
            }
        }

        $this->newLine();

        return $this->call('agentio:setup-telegram', array_filter([
            'token' => $token,
            '--no-interaction' => ! $this->interactive,
        ])) === self::SUCCESS;
    }

    /**
     * Make the rest of the run (agentio:setup-youtrack) use the chosen settings.
     *
     * @param  array{url: string, token: string, tokenChanged: bool, user: array{login: string, name: string, email: string}}|null  $connection
     */
    private function useSettings(?array $connection, string $project, string $baseBranch, string $productionBranch, string $worktrees): void
    {
        config([
            'agentio.youtrack.project' => $project,
            'agentio.base_branch' => $baseBranch,
            'agentio.production_branch' => $productionBranch,
            'agentio.worktrees_path' => $worktrees,
        ]);

        if ($connection !== null) {
            config(['agentio.youtrack.url' => $connection['url'], 'agentio.youtrack.token' => $connection['token']]);
            $this->laravel->forgetInstance(Client::class);
            $this->laravel->forgetInstance(McpClient::class);
            $this->laravel->forgetInstance(IssueRepository::class);
        }
    }

    private function tools(string $url, string $token): Tools
    {
        return new Tools(new McpClient($url, $token, (int) config('agentio.youtrack.timeout', 30), (int) config('agentio.youtrack.retries', 2)));
    }

    private function absolutePath(string $basePath, string $path): string
    {
        $path = trim($path);
        $path = (string) preg_replace('#^~(?=/|$)#', (string) getenv('HOME'), $path);
        $absolute = str_starts_with($path, '/') ? $path : rtrim($basePath, '/').'/'.$path;
        $parts = [];

        foreach (explode('/', $absolute) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return '/'.implode('/', $parts);
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
     * @param  list<SetupAction>  $actions
     */
    private function renderBranches(array $actions): void
    {
        $this->newLine();
        $this->line('<options=bold>Branches</>'.($this->dryRun ? ' (plan)' : ''));

        foreach ($actions as $action) {
            $this->components->twoColumnDetail($action->name.' <fg=gray>'.$action->detail.'</>', match ($action->status) {
                SetupStatus::Exists => '<fg=green>exists</>',
                SetupStatus::Create => $this->dryRun ? '<fg=yellow>will be created</>' : '<fg=green>created</>',
                SetupStatus::Update => '<fg=yellow>updated</>',
                SetupStatus::Warning => '<fg=yellow>warning</>',
                SetupStatus::Error => '<fg=red>error</>',
            });
        }
    }

    /**
     * @param  list<FileChange>  $changes
     */
    private function renderChanges(array $changes): void
    {
        $this->newLine();
        $this->line('<options=bold>Files</>'.($this->dryRun ? ' (plan)' : ''));
        $this->table(['Status', 'File', 'Note'], array_map(fn (FileChange $change): array => [
            $this->dryRun && in_array($change->status, [FileStatus::Created, FileStatus::Updated, FileStatus::Removed], true) ? 'will be '.$change->status->value : $change->status->value,
            $change->path,
            $change->note,
        ], $changes));

        foreach ($changes as $change) {
            if ($change->status === FileStatus::Skipped) {
                $this->components->warn("{$change->path}: {$change->note}");
            }
        }
    }

    /**
     * @param  list<Check>  $checks
     * @param  array<string, string>  $kb
     * @param  int|null  $setup  The exit code of agentio:setup-youtrack, null when it did not run
     */
    private function renderNextSteps(array $checks, string $baseBranch, array $kb, bool $connected, ?int $setup, bool $services): void
    {
        $steps = [];

        foreach ($checks as $check) {
            if (! $check->ok && $check->required) {
                $steps[] = "Fix: {$check->name} — {$check->hint}";
            }
        }

        if (! $connected) {
            $steps[] = 'Connect YouTrack: run php artisan agentio:install again and enter the URL and the token.';
        } elseif (array_diff(array_keys(KnowledgeBase::ARTICLES), array_keys($kb)) !== []) {
            $steps[] = 'Configure the YouTrack project: php artisan agentio:setup-youtrack --dry-run, then without --dry-run.';
        }

        if ($services) {
            $steps[] = 'Start the local services: docker compose up -d (PostgreSQL with the database of .env and the '.LocalServices::TEST_DATABASE.' database of the tests, Redis), then php artisan migrate.';
        }

        $steps[] = 'Commit .claude/skills/agentio-*'.($services ? ', compose.yaml, docker/, phpunit.xml, .env.example' : '')." and .agentio.json to {$baseBranch}: the epic worktrees take the skills from it (.env stays local). Point the develop server at {$baseBranch} and production at the production branch.";

        if ($setup === SetupYouTrackCommand::INCOMPLETE) {
            $steps[] = 'Fix the YouTrack board agentio:setup-youtrack reported: '.YouTrackSetup::BOARD_HINT.'.';
        } elseif ($setup === null) {
            $steps[] = 'The YouTrack project needs an agile board with columns by Stage and swimlanes by Type: agentio:setup-youtrack checks it.';
        }
        $steps[] = 'See what the loop would start: php artisan agentio:run --dry-run, then start it: php artisan agentio:run';

        if ((bool) config('agentio.ui.enabled', true)) {
            $steps[] = 'Open the dashboard: '.url(Settings::string('agentio.ui.path') ?? 'agentio');
        }

        $steps[] = 'The manual: the «Руководство по автоматизации» article in YouTrack (vendor/alexxosipov/agentio/resources/docs/AUTONOMOUS_WORKFLOW.md).';

        $this->newLine();
        $this->line('<options=bold>Next steps</>');

        foreach ($steps as $index => $step) {
            $this->line(sprintf('  %d. %s', $index + 1, $step));
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
