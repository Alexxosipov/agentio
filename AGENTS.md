# Agentio

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `alexxosipov/agentio`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Quick Commands

- Full validation: `composer test` — run it at the end of every task to confirm everything is right; it is the only full test run.
- Specific tests: `vendor/bin/pest tests/Feature/SomeTest.php --filter=...` — the only way to run single tests.
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve` (dashboard at http://127.0.0.1:8000/agentio). The served app does not see shell variables: put `YOUTRACK_URL`, `YOUTRACK_TOKEN`, `AGENTIO_PROJECT` and `AGENTIO_LOGS_PATH` into `workbench/.env` (copy of `workbench/.env.example`, git-ignored); `composer clear` removes the copy testbench makes of it.
- Never put a real YouTrack token into tests (use `Http::fake()`) or committed files.

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
