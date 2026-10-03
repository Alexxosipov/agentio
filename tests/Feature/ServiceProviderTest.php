<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Obrazmisli\Agentio\AgentioServiceProvider;
use Obrazmisli\Agentio\Process\StageMap;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\YouTrack\Client;
use Obrazmisli\Agentio\YouTrack\IssueRepository;

it('merges the package config with the documented defaults', function () {
    expect(config('agentio.youtrack.project'))->toBeNull()
        ->and(config('agentio.base_branch'))->toBeNull()
        ->and(config('agentio.merge_policy'))->toBeNull()
        ->and(config('agentio.max_parallel'))->toBe(2)
        ->and(config('agentio.max_parallel_tasks'))->toBe(2)
        ->and(config('agentio.interval'))->toBe(300)
        ->and(config('agentio.worktrees_path'))->toBe(base_path('../worktrees'))
        ->and(config('agentio.claude_binary'))->toBe('claude')
        ->and(config('agentio.logs_path'))->toBe(storage_path('logs/agents'))
        ->and(config('agentio.stage_map.In Progress'))->toBe('Develop')
        ->and(config('agentio.ui.enabled'))->toBeTrue()
        ->and(config('agentio.ui.path'))->toBe('agentio')
        ->and(config('agentio.ui.middleware'))->toBe(['web'])
        ->and(config('agentio.ui.allowed_emails'))->toBe([]);
});

it('builds the YouTrack client and repository from the config', function () {
    config([
        'agentio.youtrack.url' => 'https://example.youtrack.cloud/',
        'agentio.youtrack.token' => 'secret',
        'agentio.youtrack.project' => 'DV',
    ]);

    $repository = app(IssueRepository::class);

    expect(app(Client::class)->isConfigured())->toBeTrue()
        ->and(app(Client::class)->baseUrl())->toBe('https://example.youtrack.cloud')
        ->and($repository->project())->toBe('DV')
        ->and($repository->client())->toBe(app(Client::class));
});

it('takes the project from .agentio.json, then TP, when the config has none', function () {
    $project = hostProject();

    expect(app(IssueRepository::class)->project())->toBe('TP');

    file_put_contents($project.'/.agentio.json', json_encode(['project' => 'AB']));
    app()->forgetInstance(IssueRepository::class);

    expect(app(IssueRepository::class)->project())->toBe('AB');

    config(['agentio.youtrack.project' => 'CD']);
    app()->forgetInstance(IssueRepository::class);

    expect(app(IssueRepository::class)->project())->toBe('CD');
});

it('resolves an unconfigured client without failing', function () {
    expect(app(Client::class)->isConfigured())->toBeFalse();
});

it('builds the loop state from the logs path and the project root', function () {
    config(['agentio.logs_path' => '/var/agents']);

    $state = app(LoopState::class);

    expect($state->logsPath())->toBe('/var/agents')
        ->and($state->stopFile())->toBe(base_path('.agent-stop'));
});

it('builds the stage map from the config', function () {
    config(['agentio.stage_map' => ['Review' => 'Test']]);

    expect(app(StageMap::class)->all())->toBe(['Review' => 'Test']);
});

it('publishes the config file under the agentio-config and agentio tags', function () {
    $paths = ServiceProvider::pathsToPublish(AgentioServiceProvider::class, 'agentio-config');

    expect(array_values($paths))->toBe([config_path('agentio.php')])
        ->and((string) array_key_first($paths))->toBeFile()
        ->and(ServiceProvider::pathsToPublish(AgentioServiceProvider::class, 'agentio'))->toHaveCount(2);
});

it('registers the views publish tag and loads the views', function () {
    expect(ServiceProvider::pathsToPublish(AgentioServiceProvider::class, 'agentio-views'))->toHaveCount(1)
        ->and(view()->exists('agentio::index'))->toBeTrue();
});
