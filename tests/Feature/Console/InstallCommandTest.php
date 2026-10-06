<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\Install\EnvFile;
use Obrazmisli\Agentio\Install\Installer;
use Obrazmisli\Agentio\Install\KnowledgeBase;
use Obrazmisli\Agentio\Install\Manifest;
use Obrazmisli\Agentio\Install\Placeholders;
use Obrazmisli\Agentio\Runtime\MergePolicy;
use Obrazmisli\Agentio\Tests\Fakes\FakeYouTrackMcp;

const STUB_SKILLS = [
    'agentio-develop-task', 'agentio-dispatch', 'agentio-laravel-architect', 'agentio-plan', 'agentio-platform-skill', 'agentio-project-manager',
    'agentio-review-story', 'agentio-saloon', 'agentio-status', 'agentio-system-analyst', 'agentio-telegram-assistant', 'agentio-work-epic', 'agentio-youtrack-workflow',
];

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    config(['agentio.youtrack.url' => null, 'agentio.youtrack.token' => null, 'agentio.youtrack.project' => null, 'agentio.worktrees_path' => null]);
});

/**
 * A host project with a fake Claude Code CLI that records its calls in claude-calls.log and knows a youtrack MCP
 * server when the file claude-has-youtrack exists.
 */
function projectWithFakeClaude(bool $hasYouTrackServer = false): string
{
    $project = hostProject();
    $claude = $project.'.claude-cli';
    file_put_contents($claude, "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> '{$project}.claude-calls.log'\nif [[ \"\$1 \$2\" == 'mcp get' ]]; then [[ -f '{$project}.claude-has-youtrack' ]] && exit 0 || exit 1; fi\nexit 0\n");
    chmod($claude, 0755);
    config(['agentio.claude_binary' => $claude]);

    if ($hasYouTrackServer) {
        touch($project.'.claude-has-youtrack');
    }

    test()->beforeApplicationDestroyed(function () use ($project): void {
        foreach (['.claude-cli', '.claude-calls.log', '.claude-has-youtrack', '-worktrees'] as $suffix) {
            if (is_dir($project.$suffix)) {
                rmdir($project.$suffix);
            } elseif (is_file($project.$suffix)) {
                unlink($project.$suffix);
            }
        }
    });

    return $project;
}

/**
 * @return list<string>
 */
function claudeCalls(string $project): array
{
    return is_file($project.'.claude-calls.log') ? array_values(array_filter(explode("\n", (string) file_get_contents($project.'.claude-calls.log')))) : [];
}

/**
 * Every file under a directory, relative path => content.
 *
 * @return array<string, string>
 */
function installedFilesIn(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $files[substr($file->getPathname(), strlen($directory) + 1)] = (string) file_get_contents($file->getPathname());
    }

    ksort($files);

    return $files;
}

/**
 * @return array<string, mixed>
 */
function offline(string $project, array $options = []): array
{
    return ['--no-interaction' => true, '--project' => 'XY', '--worktrees' => $project.'-worktrees', ...$options];
}

it('installs only the skills into .claude/skills, with the placeholders rendered', function () {
    $project = projectWithFakeClaude();

    $this->artisan('agentio:install', offline($project, ['--base-branch' => 'develop', '--merge-policy' => 'pull-request', '--skip-services' => true]))
        ->expectsOutputToContain('YouTrack is skipped')
        ->assertSuccessful();

    $files = installedFilesIn($project);
    $skills = array_filter(array_keys($files), fn (string $path): bool => str_starts_with($path, '.claude/skills/'));

    expect(array_keys($files))->toBe(['.agentio.json', ...array_values($skills), '.env'])
        ->and(array_values(array_unique(array_map(fn (string $path): string => explode('/', $path)[2], $skills))))->toBe(STUB_SKILLS)
        ->and($files['.claude/skills/agentio-youtrack-workflow/SKILL.md'])->toContain('# YouTrack workflow (проект `XY`)', '| Обзор продукта | «Обзор продукта» |')
        ->and($files['.claude/skills/agentio-work-epic/SKILL.md'])->toContain('`BASE_BRANCH` (develop)', '(в этом проекте — `pull-request`)')
        ->and(implode('', $files))->not->toContain('{{', 'scripts/', 'yt.php', 'Stage')
        ->and($files['.env'])->toBe("# agentio\nAGENTIO_WORKTREES_PATH={$project}-worktrees\n")
        ->and(is_dir($project.'-worktrees'))->toBeTrue()
        ->and(claudeCalls($project))->toBe([]);

    $manifest = Manifest::load($project);

    expect($manifest->project)->toBe('XY')
        ->and($manifest->baseBranch)->toBe('develop')
        ->and($manifest->mergePolicy)->toBe('pull-request')
        ->and(array_keys($manifest->files))->toBe(array_values($skills))
        ->and($manifest->files['.claude/skills/agentio-plan/SKILL.md'])->toBe(hash('sha256', $files['.claude/skills/agentio-plan/SKILL.md']));
});

it('packages every skill the way Claude Code expects', function () {
    $project = projectWithFakeClaude();
    $this->artisan('agentio:install', offline($project))->assertSuccessful();

    foreach (STUB_SKILLS as $name) {
        $skill = (string) file_get_contents($project.'/.claude/skills/'.$name.'/SKILL.md');

        expect(preg_match('/\A---\nname: ([a-z0-9-]+)\ndescription: (.+?)\n(?:[a-z-]+: .+\n)*---\n/', $skill, $match))->toBe(1, $name)
            ->and($match[1])->toBe($name)
            ->and(mb_strlen($match[2]))->toBeLessThanOrEqual(1024)
            ->and(substr_count($skill, "\n"))->toBeLessThan(500);

        preg_match_all('/\]\(([^)#]+\.md)\)/', $skill, $links);

        foreach ($links[1] as $link) {
            expect(is_file($project.'/.claude/skills/'.$name.'/'.$link))->toBeTrue("{$name} links {$link}");
        }
    }

    expect(file_get_contents($project.'/.claude/skills/agentio-plan/SKILL.md'))->toContain('disable-model-invocation: true')
        ->and(file_get_contents($project.'/.claude/skills/agentio-work-epic/SKILL.md'))->toContain('disable-model-invocation: true')
        ->and(file_get_contents($project.'/.claude/skills/agentio-youtrack-workflow/SKILL.md'))->toContain('user-invocable: false');
});

it('tells the agents to finish with composer test and to run single tests through pest', function () {
    $project = projectWithFakeClaude();
    $this->artisan('agentio:install', offline($project))->assertSuccessful();

    $files = installedFilesIn($project);

    expect(implode('', $files))->not->toContain('agentio:test')
        ->and($files['.claude/skills/agentio-youtrack-workflow/SKILL.md'])->toContain('## Тесты', 'composer test', 'vendor/bin/pest')
        ->and($files['.claude/skills/agentio-develop-task/SKILL.md'])->toContain('Bash(composer test*) Bash(vendor/bin/pest *)', '`composer test`', '`vendor/bin/pest')
        ->and($files['.claude/skills/agentio-review-story/SKILL.md'])->toContain('Bash(composer test*)', '`composer test`')
        ->and($files['.claude/skills/agentio-work-epic/SKILL.md'])->toContain('Bash(composer test*)', '`composer test`');
});

it('is idempotent', function () {
    $project = projectWithFakeClaude();
    $this->artisan('agentio:install', offline($project))->assertSuccessful();
    $before = installedFilesIn($project);

    $this->artisan('agentio:install', offline($project))
        ->doesntExpectOutputToContain('created')
        ->doesntExpectOutputToContain('updated')
        ->assertSuccessful();

    expect(installedFilesIn($project))->toBe($before);
});

it('keeps locally edited skills unless forced and updates the ones nobody edited', function () {
    $project = projectWithFakeClaude();
    $this->artisan('agentio:install', offline($project))->assertSuccessful();
    $edited = $project.'/.claude/skills/agentio-status/SKILL.md';
    $pristine = $project.'/.claude/skills/agentio-plan/SKILL.md';
    file_put_contents($edited, "my own\n");

    // An older version of a skill that nobody edited: the hash in .agentio.json still matches.
    file_put_contents($pristine, "old version\n");
    $manifest = Manifest::load($project);
    $manifest->with(files: [...$manifest->files, '.claude/skills/agentio-plan/SKILL.md' => hash('sha256', "old version\n")])->save($project);

    $this->artisan('agentio:install', offline($project))
        ->expectsOutputToContain('.claude/skills/agentio-status/SKILL.md: differs from the stub (edited locally?); --force overwrites it')
        ->assertSuccessful();

    expect(file_get_contents($edited))->toBe("my own\n")
        ->and(file_get_contents($pristine))->toContain('name: agentio-plan');

    $this->artisan('agentio:install', offline($project, ['--force' => true]))->assertSuccessful();

    expect(file_get_contents($edited))->toContain('name: agentio-status');
});

it('removes skill files of a previous install that the package no longer ships unless they were edited', function () {
    $project = projectWithFakeClaude();
    mkdir($project.'/.claude/skills/agentio-old/references', 0777, true);
    mkdir($project.'/scripts');
    file_put_contents($project.'/.claude/skills/agentio-old/SKILL.md', "old\n");
    file_put_contents($project.'/.claude/skills/agentio-old/references/edited.md', "edited\n");
    file_put_contents($project.'/scripts/yt.php', "<?php\n");
    (new Manifest('XY', files: [
        '.claude/skills/agentio-old/SKILL.md' => hash('sha256', "old\n"),
        '.claude/skills/agentio-old/references/edited.md' => hash('sha256', "original\n"),
        'scripts/yt.php' => hash('sha256', "<?php\n"),
    ]))->save($project);

    $this->artisan('agentio:install', offline($project))
        ->expectsOutputToContain('no longer part of agentio, but edited locally: kept')
        ->assertSuccessful();

    expect(is_file($project.'/.claude/skills/agentio-old/SKILL.md'))->toBeFalse()
        ->and(is_file($project.'/.claude/skills/agentio-old/references/edited.md'))->toBeTrue()
        ->and(is_file($project.'/scripts/yt.php'))->toBeTrue()
        ->and(Manifest::load($project)->files)->toHaveKey('.claude/skills/agentio-old/references/edited.md')
        ->not->toHaveKey('scripts/yt.php');
});

it('adds the docker compose services and points .env, .env.example and phpunit.xml at them', function () {
    $project = projectWithFakeClaude();
    $phpunit = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit bootstrap="vendor/autoload.php" colors="true">
            <php>
                <env name="APP_ENV" value="testing"/>
                <env name="CACHE_STORE" value="array"/>
                <env name="DB_CONNECTION" value="sqlite"/>
                <env name="DB_DATABASE" value=":memory:"/>
                <env name="QUEUE_CONNECTION" value="sync"/>
            </php>
        </phpunit>

        XML;
    file_put_contents($project.'/phpunit.xml', $phpunit);
    file_put_contents($project.'/.env', "APP_NAME=Laravel\nDB_CONNECTION=sqlite\n# DB_HOST=127.0.0.1\n# DB_PORT=3306\n# DB_DATABASE=laravel\n# DB_USERNAME=root\n# DB_PASSWORD=\n\nREDIS_HOST=127.0.0.1\nREDIS_PASSWORD=null\nREDIS_PORT=6379\n");
    file_put_contents($project.'/.env.example', "APP_NAME=Laravel\nDB_CONNECTION=sqlite\n");

    $this->artisan('agentio:install', offline($project))
        ->expectsOutputToContain('docker compose up -d')
        ->assertSuccessful();

    expect(file_get_contents($project.'/.env'))->toBe("APP_NAME=Laravel\nDB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=laravel\nDB_USERNAME=laravel\nDB_PASSWORD=password\n\nREDIS_HOST=127.0.0.1\nREDIS_PASSWORD=null\nREDIS_PORT=6379\n\n# agentio\nAGENTIO_WORKTREES_PATH={$project}-worktrees\n")
        ->and(file_get_contents($project.'/.env.example'))->toBe("APP_NAME=Laravel\nDB_CONNECTION=pgsql\n\n# agentio\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=laravel\nDB_USERNAME=laravel\nDB_PASSWORD=password\nREDIS_HOST=127.0.0.1\nREDIS_PORT=6379\n")
        ->and(file_get_contents($project.'/phpunit.xml'))->toBe(str_replace(
            ['<env name="DB_CONNECTION" value="sqlite"/>', '<env name="DB_DATABASE" value=":memory:"/>', "<env name=\"QUEUE_CONNECTION\" value=\"sync\"/>\n"],
            ['<env name="DB_CONNECTION" value="pgsql"/>', '<env name="DB_DATABASE" value="testing"/>', "<env name=\"QUEUE_CONNECTION\" value=\"sync\"/>\n        <env name=\"DB_URL\" value=\"\"/>\n"],
            $phpunit,
        ))
        ->and(file_get_contents($project.'/compose.yaml'))->toContain("image: 'postgres:18-alpine'", "image: 'redis:7-alpine'", "POSTGRES_DB: '\${DB_DATABASE}'", './docker/postgres/initdb:/docker-entrypoint-initdb.d')
        ->and(file_get_contents($project.'/docker/postgres/initdb/01-create-testing-db.sh'))->toContain('CREATE DATABASE testing OWNER "$POSTGRES_USER";')
        ->and(is_executable($project.'/docker/postgres/initdb/01-create-testing-db.sh'))->toBeTrue()
        ->and(Manifest::load($project)->files)->toHaveKeys(['compose.yaml', 'docker/postgres/initdb/01-create-testing-db.sh']);

    $before = installedFilesIn($project);

    $this->artisan('agentio:install', offline($project))->doesntExpectOutputToContain('updated')->assertSuccessful();

    expect(installedFilesIn($project))->toBe($before);
});

it('keeps the database of a project already on PostgreSQL and fills in what is missing', function () {
    $project = projectWithFakeClaude();
    file_put_contents($project.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_DATABASE=shop\nDB_USERNAME=shop\nDB_PASSWORD=\n");

    $this->artisan('agentio:install', offline($project))->assertSuccessful();

    $env = new EnvFile($project.'/.env');

    expect($env->get('DB_DATABASE'))->toBe('shop')
        ->and($env->get('DB_USERNAME'))->toBe('shop')
        ->and($env->get('DB_PASSWORD'))->toBeNull()
        ->and($env->get('DB_PORT'))->toBe('5432')
        ->and($env->get('REDIS_HOST'))->toBe('127.0.0.1');
});

it('leaves a project on another database alone and keeps a compose.yaml of its own', function () {
    $project = projectWithFakeClaude();
    file_put_contents($project.'/.env', "DB_CONNECTION=mysql\n");
    file_put_contents($project.'/phpunit.xml', "<phpunit>\n</phpunit>\n");

    $this->artisan('agentio:install', offline($project))
        ->expectsOutputToContain('The project uses the mysql database: the docker compose services (PostgreSQL, Redis) are not added')
        ->assertSuccessful();

    expect(is_file($project.'/compose.yaml'))->toBeFalse()
        ->and(file_get_contents($project.'/phpunit.xml'))->toBe("<phpunit>\n</phpunit>\n")
        ->and((new EnvFile($project.'/.env'))->get('DB_HOST'))->toBeNull();

    $project = projectWithFakeClaude();
    file_put_contents($project.'/compose.yaml', "services: {}\n");

    $this->artisan('agentio:install', offline($project))
        ->expectsOutputToContain('compose.yaml: differs from the stub (edited locally?); --force overwrites it')
        ->assertSuccessful();

    expect(file_get_contents($project.'/compose.yaml'))->toBe("services: {}\n");
});

it('needs an explicit worktrees directory outside the project', function () {
    $project = projectWithFakeClaude();

    $this->artisan('agentio:install', ['--no-interaction' => true, '--project' => 'XY'])
        ->expectsOutputToContain('Choose where the epic worktrees live: pass --worktrees=<directory outside the project>')
        ->assertFailed();

    $this->artisan('agentio:install', ['--no-interaction' => true, '--project' => 'XY', '--worktrees' => 'worktrees'])
        ->expectsOutputToContain('Choose a directory outside the project')
        ->assertFailed();

    file_put_contents($project.'/.env', "AGENTIO_WORKTREES_PATH=../elsewhere\n");
    config(['agentio.worktrees_path' => '../elsewhere']);

    $this->artisan('agentio:install', ['--no-interaction' => true, '--project' => 'XY', '--dry-run' => true])->assertSuccessful();

    expect(is_dir($project.'/.claude'))->toBeFalse();
});

it('rejects an invalid merge policy or project key', function (array $options, string $message) {
    $project = projectWithFakeClaude();

    $this->artisan('agentio:install', [...offline($project), ...$options])
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'merge policy' => [['--merge-policy' => 'yolo'], 'Invalid merge policy'],
    'project key' => [['--project' => 'not a key'], 'Invalid YouTrack project short name'],
    'mcp scope' => [['--mcp-scope' => 'project'], 'Invalid --mcp-scope'],
]);

it('only shows the plan in a dry run', function () {
    $project = projectWithFakeClaude();

    $this->artisan('agentio:install', offline($project, ['--dry-run' => true]))
        ->expectsOutputToContain('will be created')
        ->assertSuccessful();

    expect(glob($project.'/{,.}[!.]*', GLOB_BRACE))->toBe([])
        ->and(is_dir($project.'-worktrees'))->toBeFalse();
});

it('checks the token through the MCP server, writes .env and adds the youtrack MCP server to Claude Code', function () {
    $project = projectWithFakeClaude();
    (new FakeYouTrackMcp)->fake();
    file_put_contents($project.'/.env', "APP_NAME=Laravel\nAGENTIO_PROJECT=OLD\n");

    $this->artisan('agentio:install', offline($project, ['--youtrack-url' => 'https://yt.example.com/', '--token' => 'secret-token', '--skip-services' => true]))
        ->expectsOutputToContain('YouTrack https://yt.example.com (MCP): signed in as Agent Smith (agent).')
        ->expectsOutputToContain('Added the youtrack MCP server in Claude Code (https://yt.example.com/mcp, scope local')
        ->doesntExpectOutputToContain('secret-token')
        ->assertSuccessful();

    expect(file_get_contents($project.'/.env'))->toBe("APP_NAME=Laravel\nAGENTIO_PROJECT=XY\n\n# agentio\nYOUTRACK_URL=https://yt.example.com\nYOUTRACK_TOKEN=secret-token\nAGENTIO_WORKTREES_PATH={$project}-worktrees\n")
        ->and(claudeCalls($project))->toBe([
            'mcp get youtrack',
            'mcp add --transport http --scope local youtrack https://yt.example.com/mcp --header Authorization: Bearer secret-token',
        ])
        ->and(Http::recorded(fn ($request): bool => ! str_ends_with(strtok($request->url(), '?') ?: '', '/mcp')))->toBeEmpty();
});

it('keeps the youtrack MCP server Claude Code already has, and replaces it when the token changes', function () {
    $project = projectWithFakeClaude(hasYouTrackServer: true);
    (new FakeYouTrackMcp)->fake();
    config(['agentio.youtrack.url' => FakeYouTrackMcp::URL, 'agentio.youtrack.token' => 'secret-token']);

    $this->artisan('agentio:install', offline($project))
        ->expectsOutputToContain('Claude Code already has the youtrack MCP server for this project: kept.')
        ->assertSuccessful();

    expect(claudeCalls($project))->toBe(['mcp get youtrack']);

    config(['agentio.youtrack.token' => 'old-token']);

    $this->artisan('agentio:install', offline($project, ['--token' => 'secret-token', '--mcp-scope' => 'user']))
        ->expectsOutputToContain('Replaced the youtrack MCP server')
        ->assertSuccessful();

    expect(array_slice(claudeCalls($project), 1))->toBe([
        'mcp get youtrack',
        'mcp remove --scope user youtrack',
        'mcp add --transport http --scope user youtrack https://yt.example.com/mcp --header Authorization: Bearer secret-token',
    ]);
});

it('fails without writing anything when YouTrack rejects the token', function () {
    $project = projectWithFakeClaude();
    (new FakeYouTrackMcp)->fake();

    $this->artisan('agentio:install', offline($project, ['--youtrack-url' => 'https://yt.example.com', '--token' => 'wrong-token']))
        ->expectsOutputToContain('Cannot reach the YouTrack MCP server at https://yt.example.com/mcp')
        ->doesntExpectOutputToContain('wrong-token')
        ->assertFailed();

    expect(glob($project.'/{,.}[!.]*', GLOB_BRACE))->toBe([])
        ->and(claudeCalls($project))->toBe([]);
});

it('creates a missing project only with --create-project when not interactive', function () {
    $project = projectWithFakeClaude(hasYouTrackServer: true);
    (new FakeYouTrackMcp(project: 'OTHER'))->fake();
    fakeYouTrackRestApi();
    config(['agentio.youtrack.url' => FakeYouTrackMcp::URL, 'agentio.youtrack.token' => 'secret-token']);

    $this->artisan('agentio:install', offline($project))
        ->expectsOutputToContain('The YouTrack project XY does not exist or the token cannot see it: create it in YouTrack, or pass --create-project')
        ->assertSuccessful();

    expect(restWrites())->toBe([]);

    $this->artisan('agentio:install', offline($project, ['--create-project' => true, '--project-name' => 'Our product']))
        ->expectsOutputToContain('Created the YouTrack project XY «Our product» led by agent.')
        ->assertSuccessful();

    expect(restWrites())->toBe(['POST admin/projects {"name":"Our product","shortName":"XY","leader":{"id":"1-1"}}']);
});

it('configures the YouTrack project with --setup-youtrack and records the knowledge base ids in the skills', function () {
    $project = projectWithFakeClaude(hasYouTrackServer: true);
    (new FakeYouTrackMcp)->fake();
    fakeYouTrackRestApi();
    config(['agentio.youtrack.url' => FakeYouTrackMcp::URL, 'agentio.youtrack.token' => 'secret-token']);

    $this->artisan('agentio:install', offline($project, ['--setup-youtrack' => true]))
        ->expectsOutputToContain('Setting up of the YouTrack project XY')
        ->assertSuccessful();

    $manifest = Manifest::load($project);

    expect($manifest->kb)->toHaveCount(count(KnowledgeBase::ARTICLES))
        ->and(file_get_contents($project.'/.claude/skills/agentio-youtrack-workflow/SKILL.md'))->toContain('| Обзор продукта | '.$manifest->kb['overview'].' |')
        ->and($manifest->files)->toHaveCount(count((new Installer($project, dirname(__DIR__, 3).'/stubs', new Placeholders('XY', 'main', MergePolicy::LocalBranch), services: true))->stubFiles()));
});

it('installs the skills but fails when the YouTrack project has no board set up for the cycle', function () {
    $project = projectWithFakeClaude(hasYouTrackServer: true);
    (new FakeYouTrackMcp)->fake();
    fakeYouTrackRestApi(['agiles' => []]);
    config(['agentio.youtrack.url' => FakeYouTrackMcp::URL, 'agentio.youtrack.token' => 'secret-token']);

    $this->artisan('agentio:install', offline($project, ['--setup-youtrack' => true]))
        ->expectsOutputToContain('the project has no agile board')
        ->expectsOutputToContain('Fix the YouTrack board agentio:setup-youtrack reported')
        ->assertFailed();

    expect(Manifest::load($project)->kb)->toHaveCount(count(KnowledgeBase::ARTICLES))
        ->and($project.'/.claude/skills/agentio-work-epic/SKILL.md')->toBeFile();
});

it('asks for the connection, the project and the settings, and offers the setup', function () {
    $project = projectWithFakeClaude();
    (new FakeYouTrackMcp)->fake();
    file_put_contents($project.'/.env', "YOUTRACK_TOKEN=expired\n");
    $worktrees = $project.'-worktrees';

    $this->artisan('agentio:install', ['--dry-run' => true])
        ->expectsQuestion('YouTrack URL', 'https://yt.example.com/')
        ->expectsConfirmation('A YouTrack token is already set (environment or .env). Keep it?', 'yes')
        ->expectsOutputToContain('Cannot reach the YouTrack MCP server at https://yt.example.com/mcp')
        ->expectsConfirmation('Enter the YouTrack URL and token again?', 'yes')
        ->expectsQuestion('YouTrack URL', 'https://yt.example.com')
        ->expectsConfirmation('A YouTrack token is already set (environment or .env). Keep it?', 'no')
        ->expectsQuestion('YouTrack permanent token', 'secret-token')
        ->expectsQuestion('YouTrack project short name', 'XY')
        ->expectsQuestion('Production branch', 'main')
        ->expectsQuestion('Development branch', 'dev')
        ->expectsChoice('What happens to an epic branch when the agents are done?', 'local-branch', [
            'local-branch' => 'local-branch — the branch stays local, a human merges it',
            'pull-request' => 'pull-request — the branch is pushed and a PR opened (gh), a human merges it',
            'auto-merge' => 'auto-merge — merged automatically after a green full test run',
        ])
        ->expectsQuestion('Directory of the epic worktrees', $worktrees)
        ->expectsConfirmation('Configure the YouTrack project XY now?', 'no')
        ->expectsOutputToContain('Would add the youtrack MCP server (https://yt.example.com/mcp, scope local) in Claude Code.')
        ->doesntExpectOutputToContain('secret-token')
        ->assertSuccessful();

    expect(file_get_contents($project.'/.env'))->toBe("YOUTRACK_TOKEN=expired\n");
});

it('installs without YouTrack when the URL is left empty', function () {
    $project = projectWithFakeClaude();

    $this->artisan('agentio:install')
        ->expectsQuestion('YouTrack URL', '')
        ->expectsQuestion('YouTrack project short name', 'XY')
        ->expectsQuestion('Production branch', 'main')
        ->expectsQuestion('Development branch', 'dev')
        ->expectsChoice('What happens to an epic branch when the agents are done?', 'auto-merge', [
            'local-branch' => 'local-branch — the branch stays local, a human merges it',
            'pull-request' => 'pull-request — the branch is pushed and a PR opened (gh), a human merges it',
            'auto-merge' => 'auto-merge — merged automatically after a green full test run',
        ])
        ->expectsQuestion('Directory of the epic worktrees', '../'.basename($project).'-worktrees')
        ->expectsConfirmation('Connect your own Telegram bot (questions of the agents, short reports, ideas by voice)?', 'no')
        ->expectsOutputToContain('YouTrack is skipped')
        ->assertSuccessful();

    expect((new EnvFile($project.'/.env'))->get('AGENTIO_WORKTREES_PATH'))->toBe($project.'-worktrees')
        ->and(Manifest::load($project)->mergePolicy)->toBe('auto-merge');
});

it('reports missing preconditions with hints', function () {
    $project = projectWithFakeClaude();
    config(['agentio.claude_binary' => '/nonexistent/claude']);

    $this->artisan('agentio:install', offline($project))
        ->expectsOutputToContain('git repository')
        ->expectsOutputToContain('Fix: Claude Code CLI (/nonexistent/claude)')
        ->assertSuccessful();
});

it('sets up the Telegram bot with the token it is given', function () {
    $project = projectWithFakeClaude();
    Process::fake(['*horizon:status*' => Process::result('Horizon is running.'), '*' => Process::result()]);
    fakeTelegram(['getMe' => ['ok' => true, 'result' => ['id' => 9, 'first_name' => 'PM', 'username' => 'xy_pm_bot']]]);
    $token = '987654321:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    $this->artisan('agentio:install', offline($project, ['--telegram-token' => $token]))
        ->expectsOutputToContain('the bot @xy_pm_bot')
        ->assertSuccessful();

    expect((new EnvFile($project.'/.env'))->get('AGENTIO_TELEGRAM_BOT_TOKEN'))->toBe($token);
});
