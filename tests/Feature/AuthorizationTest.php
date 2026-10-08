<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Obrazmisli\Agentio\Agentio;
use Obrazmisli\Agentio\AgentioServiceProvider;
use Workbench\App\Models\User;

function user(string $email): User
{
    return new User(['name' => 'Someone', 'email' => $email]);
}

function bootAgentioRoutes(): void
{
    app('router')->setRoutes(new RouteCollection);
    (new AgentioServiceProvider(app()))->boot();
    app('router')->getRoutes()->refreshNameLookups();
}

it('serves the dashboard to everyone in the local environment', function () {
    app()->detectEnvironment(fn (): string => 'local');

    $this->get('/agentio')->assertOk()->assertSee('Agentio');
});

it('forbids guests outside the local environment', function () {
    $this->get('/agentio')->assertForbidden();
});

it('allows the users listed in allowed_emails', function () {
    config(['agentio.ui.allowed_emails' => ['lead@example.com']]);

    $this->actingAs(user('lead@example.com'))->get('/agentio')->assertOk();
    $this->actingAs(user('other@example.com'))->get('/agentio')->assertForbidden();
});

it('lets the application replace the check with Agentio::auth()', function () {
    Agentio::auth(fn (Request $request): bool => $request->hasHeader('X-Ops'));

    $this->get('/agentio', ['X-Ops' => '1'])->assertOk();
    $this->get('/agentio')->assertForbidden();

    Agentio::auth(null);
    app()->detectEnvironment(fn (): string => 'local');

    $this->get('/agentio')->assertOk();
});

it('keeps a viewAgentio gate the application defined itself', function () {
    Gate::define('viewAgentio', fn (?User $user = null): bool => true);

    (new AgentioServiceProvider(app()))->boot();

    $this->get('/agentio')->assertOk();
});

it('mounts the routes at the configured path and domain', function () {
    config(['agentio.ui.path' => 'ops/agents', 'agentio.ui.domain' => 'ops.example.com']);
    bootAgentioRoutes();
    app()->detectEnvironment(fn (): string => 'local');

    expect(route('agentio.index'))->toBe('http://ops.example.com/ops/agents');

    $this->get('http://ops.example.com/ops/agents')->assertOk();
});

it('does not register the routes when the UI is disabled', function () {
    config(['agentio.ui.enabled' => false]);
    bootAgentioRoutes();

    expect(Route::has('agentio.index'))->toBeFalse();
});

it('needs the manageAgentio gate to act from the dashboard', function () {
    config(['agentio.ui.allowed_emails' => ['dev@example.com']]);
    Gate::define('manageAgentio', fn (User $user): bool => $user->email === 'lead@example.com');

    $this->actingAs(user('dev@example.com'))->getJson('/agentio/api/epics/XY-2/review')
        ->assertOk()
        ->assertJsonPath('actions', false);
    $this->actingAs(user('dev@example.com'))->postJson('/agentio/api/epics/XY-2/accept')
        ->assertForbidden()
        ->assertJsonPath('message', 'Нет права управлять эпиками (gate manageAgentio).');
});
