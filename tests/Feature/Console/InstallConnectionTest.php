<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Obrazmisli\Agentio\Install\EnvFile;

const CONNECTION_TOKEN = 'perm:c2VjcmV0.dG9rZW4=.xyz';

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    config(['agentio.youtrack.url' => null, 'agentio.youtrack.token' => null, 'agentio.youtrack.project' => null]);
});

/**
 * A fake YouTrack: the token owner, the projects visible to the token (created ones are added) and, for the
 * project setup, empty fields, bundles, tags, saved searches and articles.
 *
 * @param  list<string>  $projects  Short names of the existing projects
 */
function fakeYouTrackForInstall(array $projects = ['XY'], string $token = CONNECTION_TOKEN, int $createStatus = 200): void
{
    $state = array_map(fn (string $shortName): array => ['id' => '0-'.strlen($shortName), 'shortName' => $shortName, 'name' => 'Project '.$shortName], $projects);

    Http::fake(function (Request $request) use (&$state, $token, $createStatus) {
        $path = (string) preg_replace('#^https://yt\.example\.com/api/#', '', strtok($request->url(), '?') ?: '');
        $get = $request->method() === 'GET';

        if ($request->header('Authorization') !== ['Bearer '.$token]) {
            return Http::response(['error' => 'Unauthorized', 'error_description' => 'Invalid token'], 401);
        }

        if ($path === 'admin/projects' && ! $get) {
            if ($createStatus !== 200) {
                return Http::response(['error_description' => 'Forbidden'], $createStatus);
            }

            $state[] = ['id' => '0-99', 'shortName' => $request['shortName'], 'name' => $request['name']];

            return Http::response(end($state));
        }

        return match (true) {
            $get && $path === 'users/me' => Http::response(['id' => '2-7', 'login' => 'jane', 'fullName' => 'Jane Doe']),
            $get && $path === 'admin/projects' => Http::response((int) ($request['$skip'] ?? 0) > 0 ? [] : $state),
            $get => Http::response([]),
            default => Http::response(['id' => 'new-1', 'name' => $request['name'] ?? null]),
        };
    });
}

/**
 * @return array<array-key, mixed>
 */
function jsonFileOf(string $path): array
{
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

it('checks the connection and records it in .env, settings.local.json and .mcp.json', function () {
    $project = hostProject();
    fakeYouTrackForInstall();
    file_put_contents($project.'/.env', "APP_NAME=Acme\nYOUTRACK_URL=https://old.example.com\n# comment\n");
    mkdir($project.'/.claude');
    file_put_contents($project.'/.claude/settings.local.json', json_encode(['permissions' => ['allow' => ['Bash(ls)']], 'env' => ['FOO' => 'bar', 'YOUTRACK_TOKEN' => 'old']]));
    file_put_contents($project.'/.mcp.json', json_encode(['mcpServers' => ['laravel-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']]]]));

    $this->artisan('agentio:install', ['--no-interaction' => true, '--youtrack-url' => 'https://yt.example.com/', '--token' => CONNECTION_TOKEN, '--project' => 'XY'])
        ->expectsOutputToContain('YouTrack https://yt.example.com: signed in as Jane Doe (jane).')
        ->expectsOutputToContain('set YOUTRACK_URL, YOUTRACK_TOKEN, AGENTIO_PROJECT')
        ->expectsOutputToContain('env: YOUTRACK_URL, YOUTRACK_TOKEN')
        ->expectsOutputToContain('added MCP server youtrack')
        ->expectsOutputToContain('Start `claude` in the project and trust the folder')
        ->doesntExpectOutputToContain(CONNECTION_TOKEN)
        ->doesntExpectOutputToContain('claude mcp add')
        ->doesntExpectOutputToContain('does not exist')
        ->assertSuccessful();

    expect(file_get_contents($project.'/.env'))->toBe("APP_NAME=Acme\nYOUTRACK_URL=https://yt.example.com\n# comment\n\n# agentio\nYOUTRACK_TOKEN=".CONNECTION_TOKEN."\nAGENTIO_PROJECT=XY\n")
        ->and(jsonFileOf($project.'/.claude/settings.local.json'))->toBe([
            'permissions' => ['allow' => ['Bash(ls)']],
            'env' => ['FOO' => 'bar', 'YOUTRACK_TOKEN' => CONNECTION_TOKEN, 'YOUTRACK_URL' => 'https://yt.example.com'],
        ])
        ->and(jsonFileOf($project.'/.mcp.json')['mcpServers'])->toBe([
            'laravel-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']],
            'youtrack' => ['type' => 'http', 'url' => '${YOUTRACK_URL}/mcp', 'headers' => ['Authorization' => 'Bearer ${YOUTRACK_TOKEN}']],
        ])
        ->and(file_get_contents($project.'/.mcp.json'))->not->toContain(CONNECTION_TOKEN)
        ->and(jsonFileOf($project.'/.claude/settings.json')['enabledMcpjsonServers'])->toContain('youtrack')
        ->and(file_get_contents($project.'/.gitignore'))->toContain("/.claude/settings.local.json\n/.env\n");

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/users/me'));

    $this->artisan('agentio:install', ['--no-interaction' => true, '--youtrack-url' => 'https://yt.example.com', '--token' => CONNECTION_TOKEN])
        ->doesntExpectOutputToContain('updated')
        ->doesntExpectOutputToContain('created')
        ->assertSuccessful();
});

it('takes the connection from the environment and from .env', function () {
    $project = hostProject();
    fakeYouTrackForInstall();
    file_put_contents($project.'/.env', 'export YOUTRACK_TOKEN="'.CONNECTION_TOKEN."\"\nAGENTIO_PROJECT=XY # ours\n");
    config(['agentio.youtrack.url' => 'https://yt.example.com']);

    $this->artisan('agentio:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Installing agentio: YouTrack project XY')
        ->expectsOutputToContain('signed in as Jane Doe')
        ->assertSuccessful();

    expect(file_get_contents($project.'/.env'))->toBe('export YOUTRACK_TOKEN="'.CONNECTION_TOKEN."\"\nAGENTIO_PROJECT=XY # ours\n\n# agentio\nYOUTRACK_URL=https://yt.example.com\n")
        ->and(jsonFileOf($project.'/.claude/settings.local.json')['env'])->toBe(['YOUTRACK_URL' => 'https://yt.example.com', 'YOUTRACK_TOKEN' => CONNECTION_TOKEN]);
});

it('fails without writing anything when YouTrack rejects the token', function () {
    $project = hostProject();
    fakeYouTrackForInstall();

    $this->artisan('agentio:install', ['--no-interaction' => true, '--youtrack-url' => 'https://yt.example.com', '--token' => 'wrong-token', '--project' => 'XY'])
        ->expectsOutputToContain('Cannot access YouTrack at https://yt.example.com: YouTrack GET users/me failed with HTTP 401: Invalid token')
        ->doesntExpectOutputToContain('wrong-token')
        ->assertFailed();

    expect(glob($project.'/{,.}[!.]*', GLOB_BRACE))->toBe([]);
});

it('rejects an answer that is not a YouTrack user', function () {
    hostProject();
    Http::fake(['*' => Http::response(['ok' => true])]);

    $this->artisan('agentio:install', ['--no-interaction' => true, '--youtrack-url' => 'https://yt.example.com', '--token' => CONNECTION_TOKEN])
        ->expectsOutputToContain('returned no user: is the URL the YouTrack instance itself?')
        ->assertFailed();
});

it('installs without YouTrack when nothing is configured', function () {
    $project = hostProject();

    $this->artisan('agentio:install', ['--no-interaction' => true, '--project' => 'XY'])
        ->expectsOutputToContain('Connect YouTrack: run php artisan agentio:install again')
        ->expectsOutputToContain('run php artisan agentio:install and enter it, or set YOUTRACK_URL in .env')
        ->assertSuccessful();

    expect(file_get_contents($project.'/.env'))->toBe("# agentio\nAGENTIO_PROJECT=XY\n")
        ->and($project.'/.claude/settings.local.json')->not->toBeFile()
        ->and(jsonFileOf($project.'/.mcp.json')['mcpServers'])->toHaveKey('youtrack');

    Http::assertNothingSent();
});

it('creates a missing project only with --create-project when not interactive', function () {
    hostProject();
    fakeYouTrackForInstall(projects: []);
    $options = ['--no-interaction' => true, '--youtrack-url' => 'https://yt.example.com', '--token' => CONNECTION_TOKEN, '--project' => 'NEW'];

    $this->artisan('agentio:install', $options)
        ->expectsOutputToContain('The YouTrack project NEW does not exist or the token cannot see it: create it in YouTrack, or pass --create-project')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

    $this->artisan('agentio:install', [...$options, '--create-project' => true, '--project-name' => 'New product', '--dry-run' => true])
        ->expectsOutputToContain('Would create the YouTrack project NEW «New product» led by jane.')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

    $this->artisan('agentio:install', [...$options, '--create-project' => true, '--project-name' => 'New product'])
        ->expectsOutputToContain('Created the YouTrack project NEW «New product» led by jane.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_starts_with($request->url(), 'https://yt.example.com/api/admin/projects?')
        && $request->data() === ['name' => 'New product', 'shortName' => 'NEW', 'leader' => ['id' => '2-7']]);

    // The project exists now: nothing is created again.
    $this->artisan('agentio:install', [...$options, '--create-project' => true])->doesntExpectOutputToContain('Created')->assertSuccessful();

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
});

it('names a created project after its short name by default', function () {
    hostProject();
    fakeYouTrackForInstall(projects: []);

    $this->artisan('agentio:install', ['--no-interaction' => true, '--youtrack-url' => 'https://yt.example.com', '--token' => CONNECTION_TOKEN, '--project' => 'NEW', '--create-project' => true])
        ->expectsOutputToContain('Created the YouTrack project NEW «NEW»')
        ->assertSuccessful();
});

it('reports a refused project creation', function () {
    hostProject();
    fakeYouTrackForInstall(projects: [], createStatus: 403);

    $this->artisan('agentio:install', ['--no-interaction' => true, '--youtrack-url' => 'https://yt.example.com', '--token' => CONNECTION_TOKEN, '--project' => 'NEW', '--create-project' => true])
        ->expectsOutputToContain('Cannot create the YouTrack project NEW: YouTrack POST admin/projects failed with HTTP 403: Forbidden (the token needs the permission to create projects).')
        ->assertFailed();
});

it('reports a failure to read the projects', function () {
    hostProject();
    Http::fake(fn (Request $request) => str_contains($request->url(), 'users/me')
        ? Http::response(['id' => '2-7', 'login' => 'jane', 'fullName' => ''])
        : Http::response(['error_description' => 'Forbidden'], 403));

    $this->artisan('agentio:install', ['--no-interaction' => true, '--youtrack-url' => 'https://yt.example.com', '--token' => CONNECTION_TOKEN, '--project' => 'XY'])
        ->expectsOutputToContain('signed in as jane (jane)')
        ->expectsOutputToContain('Cannot read the YouTrack projects: YouTrack GET admin/projects failed with HTTP 403: Forbidden')
        ->assertFailed();
});

it('does not write the connection in a dry run and never shows the token', function () {
    $project = hostProject();
    fakeYouTrackForInstall();

    $this->artisan('agentio:install', ['--no-interaction' => true, '--youtrack-url' => 'https://yt.example.com', '--token' => CONNECTION_TOKEN, '--project' => 'XY', '--dry-run' => true])
        ->expectsOutputToContain('Dry run, nothing is changed.')
        ->expectsOutputToContain('set YOUTRACK_URL, YOUTRACK_TOKEN, AGENTIO_PROJECT')
        ->doesntExpectOutputToContain(CONNECTION_TOKEN)
        ->assertSuccessful();

    expect(glob($project.'/{,.}[!.]*', GLOB_BRACE))->toBe([]);
});

it('keeps a youtrack MCP server of the project unless forced and leaves invalid files alone', function () {
    $project = hostProject();
    mkdir($project.'/.claude');
    $own = ['mcpServers' => ['youtrack' => ['type' => 'http', 'url' => 'https://own.example.com/mcp']]];
    file_put_contents($project.'/.mcp.json', json_encode($own));
    file_put_contents($project.'/.claude/settings.local.json', '{ invalid');
    fakeYouTrackForInstall();
    $options = ['--no-interaction' => true, '--youtrack-url' => 'https://yt.example.com', '--token' => CONNECTION_TOKEN, '--project' => 'XY'];

    $this->artisan('agentio:install', $options)
        ->expectsOutputToContain('has its own youtrack MCP server; --force replaces it')
        ->expectsOutputToContain('not valid JSON, left as is: put YOUTRACK_URL, YOUTRACK_TOKEN into its "env" by hand')
        ->assertSuccessful();

    expect(jsonFileOf($project.'/.mcp.json'))->toBe($own)
        ->and(file_get_contents($project.'/.claude/settings.local.json'))->toBe('{ invalid');

    $this->artisan('agentio:install', [...$options, '--force' => true])->expectsOutputToContain('replaced MCP server youtrack (--force)')->assertSuccessful();

    expect(jsonFileOf($project.'/.mcp.json')['mcpServers']['youtrack']['url'])->toBe('${YOUTRACK_URL}/mcp');

    file_put_contents($project.'/.mcp.json', '[1');

    $this->artisan('agentio:install', $options)->expectsOutputToContain('not valid JSON, left as is')->assertSuccessful();

    expect(file_get_contents($project.'/.mcp.json'))->toBe('[1');
});

it('asks for the connection and the project, creates the project and offers the setup', function () {
    $project = hostProject();
    fakeYouTrackForInstall(projects: []);

    $this->artisan('agentio:install', ['--dry-run' => true])
        ->expectsQuestion('YouTrack URL', 'https://yt.example.com/')
        ->expectsQuestion('YouTrack permanent token', CONNECTION_TOKEN)
        ->expectsQuestion('YouTrack project short name', 'NEW')
        ->expectsConfirmation('The YouTrack project NEW does not exist (or the token cannot see it). Create it?', 'yes')
        ->expectsQuestion('Name of the new YouTrack project', 'New product')
        ->expectsConfirmation('Configure the YouTrack project NEW now?', 'yes')
        ->expectsOutputToContain('Would create the YouTrack project NEW «New product» led by jane.')
        ->expectsOutputToContain('does not exist yet, so its setup cannot be planned: run without --dry-run.')
        ->doesntExpectOutputToContain(CONNECTION_TOKEN)
        ->assertSuccessful();

    expect(glob($project.'/{,.}[!.]*', GLOB_BRACE))->toBe([]);
});

it('asks again when YouTrack rejects the token and keeps a token that works', function () {
    $project = hostProject();
    fakeYouTrackForInstall();
    file_put_contents($project.'/.env', "YOUTRACK_URL=https://yt.example.com\nYOUTRACK_TOKEN=expired\nAGENTIO_PROJECT=XY\n");

    $this->artisan('agentio:install')
        ->expectsQuestion('YouTrack URL', 'https://yt.example.com')
        ->expectsConfirmation('A YouTrack token is already set. Keep it?', 'yes')
        ->expectsOutputToContain('Cannot access YouTrack at https://yt.example.com: YouTrack GET users/me failed with HTTP 401')
        ->expectsConfirmation('Enter the YouTrack URL and token again?', 'yes')
        ->expectsQuestion('YouTrack URL', 'https://yt.example.com')
        ->expectsConfirmation('A YouTrack token is already set. Keep it?', 'no')
        ->expectsQuestion('YouTrack permanent token', CONNECTION_TOKEN)
        ->expectsQuestion('YouTrack project short name', 'XY')
        ->expectsConfirmation('Configure the YouTrack project XY now?', 'no')
        ->expectsOutputToContain('signed in as Jane Doe')
        ->assertSuccessful();

    expect(file_get_contents($project.'/.env'))->toBe("YOUTRACK_URL=https://yt.example.com\nYOUTRACK_TOKEN=".CONNECTION_TOKEN."\nAGENTIO_PROJECT=XY\n");

    // Next time the token is known and kept.
    $this->artisan('agentio:install')
        ->expectsQuestion('YouTrack URL', 'https://yt.example.com')
        ->expectsConfirmation('A YouTrack token is already set. Keep it?', 'yes')
        ->expectsQuestion('YouTrack project short name', 'XY')
        ->expectsConfirmation('Configure the YouTrack project XY now?', 'no')
        ->assertSuccessful();
});

it('stops when the user gives up on the connection', function () {
    hostProject();
    fakeYouTrackForInstall();

    $this->artisan('agentio:install')
        ->expectsQuestion('YouTrack URL', 'https://yt.example.com')
        ->expectsQuestion('YouTrack permanent token', 'wrong-token')
        ->expectsConfirmation('Enter the YouTrack URL and token again?', 'no')
        ->assertFailed();
});

it('installs without YouTrack when the URL is left empty, and keeps a declined project missing', function () {
    $project = hostProject();

    $this->artisan('agentio:install')
        ->expectsQuestion('YouTrack URL', '')
        ->expectsQuestion('YouTrack project short name', 'XY')
        ->expectsOutputToContain('YouTrack is skipped: the connection is neither checked nor recorded.')
        ->assertSuccessful();

    expect(file_get_contents($project.'/.env'))->toBe("# agentio\nAGENTIO_PROJECT=XY\n");

    fakeYouTrackForInstall(projects: []);

    $this->artisan('agentio:install')
        ->expectsQuestion('YouTrack URL', 'https://yt.example.com')
        ->expectsQuestion('YouTrack permanent token', CONNECTION_TOKEN)
        ->expectsQuestion('YouTrack project short name', 'XY')
        ->expectsConfirmation('The YouTrack project XY does not exist (or the token cannot see it). Create it?', 'no')
        ->expectsOutputToContain('Create the YouTrack project XY before starting the cycle.')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('validates the answers', function (array $answers, string $error) {
    hostProject();
    $command = $this->artisan('agentio:install');

    foreach ($answers as [$question, $answer]) {
        $command->expectsQuestion($question, $answer);
    }

    // Under test, Laravel stops at the first invalid answer instead of asking again.
    $command->expectsOutputToContain($error)->assertFailed();
})->with([
    'url' => [[['YouTrack URL', 'yt.example.com']], 'Enter the address of the YouTrack instance'],
    'project' => [[['YouTrack URL', ''], ['YouTrack project short name', 'not a key']], 'Use letters, digits and _, starting with a letter'],
]);

it('does not ask for what the options give', function () {
    $project = hostProject();
    fakeYouTrackForInstall(projects: []);
    file_put_contents($project.'/.env', "YOUTRACK_TOKEN=from-env\n");

    $this->artisan('agentio:install', ['--youtrack-url' => 'https://yt.example.com', '--token' => CONNECTION_TOKEN, '--project' => 'NEW', '--create-project' => true, '--project-name' => 'New product'])
        ->expectsConfirmation('Configure the YouTrack project NEW now?', 'no')
        ->expectsOutputToContain('Created the YouTrack project NEW «New product» led by jane.')
        ->assertSuccessful();

    expect((new EnvFile($project.'/.env'))->get('YOUTRACK_TOKEN'))->toBe(CONNECTION_TOKEN);
});

it('asks for everything again when the given connection is rejected', function () {
    hostProject();
    fakeYouTrackForInstall();

    $this->artisan('agentio:install', ['--youtrack-url' => 'https://yt.example.com', '--token' => 'wrong-token', '--project' => 'XY'])
        ->expectsOutputToContain('HTTP 401')
        ->expectsConfirmation('Enter the YouTrack URL and token again?', 'yes')
        ->expectsQuestion('YouTrack URL', 'https://yt.example.com')
        ->expectsConfirmation('A YouTrack token is already set. Keep it?', 'no')
        ->expectsQuestion('YouTrack permanent token', CONNECTION_TOKEN)
        ->expectsConfirmation('Configure the YouTrack project XY now?', 'no')
        ->assertSuccessful();
});

it('runs the YouTrack setup when it is confirmed', function () {
    hostProject();
    fakeYouTrackForInstall();

    $this->artisan('agentio:install', ['--dry-run' => true])
        ->expectsQuestion('YouTrack URL', 'https://yt.example.com')
        ->expectsQuestion('YouTrack permanent token', CONNECTION_TOKEN)
        ->expectsQuestion('YouTrack project short name', 'XY')
        ->expectsConfirmation('Configure the YouTrack project XY now?', 'yes')
        ->expectsOutputToContain('YouTrack project setup (plan)')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});
