<?php

declare(strict_types=1);

use Obrazmisli\Agentio\Agentio;

it('resolves the singleton', function () {
    expect(app(Agentio::class))->toBeInstanceOf(Agentio::class);
});

it('returns the same instance from the container', function () {
    expect(app(Agentio::class))->toBe(app(Agentio::class));
});

it('merges the package config', function () {
    expect(config('agentio.placeholder'))->toBe('default');
});

it('loads the package views', function () {
    expect(view()->exists('agentio::placeholder'))->toBeTrue();
});

it('registers the artisan command', function () {
    $this->artisan('agentio:placeholder')
        ->expectsOutputToContain('Agentio placeholder command executed.')
        ->assertSuccessful();
});
