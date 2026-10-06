---
name: package-testing
description: "Use this skill when writing, editing, fixing, or reviewing package tests with Pest 4/5 and Orchestra Testbench, including TDD, feature tests, unit tests, type coverage, arch tests, workbench behavior, commands, routes, config, migrations, and publishable resources."
license: MIT
metadata:
  author: laravel
---

# Package Testing

## Primary Goal

Prove package behavior with Pest 4/5, Orchestra Testbench, and the local `tests/TestCase.php` setup before or alongside implementation.

## Workflow

1. Start with TDD: write the smallest failing package test for the requested behavior, then implement the smallest change that makes it pass.
2. Cover happy-path, unhappy-path, and edge-case behavior when the feature has meaningful failure modes.
3. Prefer focused feature tests for package integration behavior and arch tests for broad constraints.
4. Run tests only two ways: specific tests through Pest while iterating (`vendor/bin/pest tests/Feature/SomeTest.php --filter ...`), and `composer test` at the end of every task to confirm everything is right (PHPStan, Pint, type coverage and the whole suite). A task is not done until `composer test` passes. Do not pipe test output into `tail` or `head`.
5. Fake every outside system: YouTrack with `Http::fake()` / `FakeYouTrackMcp`, the Telegram Bot API with `fakeTelegram([...])` and the transcription APIs with `Http::fake()` (`Http::preventStrayRequests()` is on for the whole suite), processes of the `Process` facade with `Process::fake()` (stray processes are refused), time with `$this->travel()` and `Sleep::fake()`.
6. Keep real package tests in the suite and remove only throwaway tests that were explicitly created for local scaffolding experiments.

## References

- `tests/TestCase.php`
- `tests/Pest.php`
- `tests/Feature/`
- `tests/Unit/`
- `tests/ArchTest.php`
- `composer.json` scripts

## Examples

- Test config merge and config override by asserting default package config, then overriding the host config value in the Testbench app.
- Test publishable assets, migrations, views, lang files, or config by invoking vendor publish behavior and asserting the target path exists.
- Test routes with Testbench HTTP requests, commands with Artisan assertions, migrations with a SQLite test database, and workbench behavior after `composer build` when needed.

## Anti-Patterns

- Deleting real package tests because they are inconvenient.
- Relying only on smoke tests when behavior needs assertions.
- Testing implementation details when observable package behavior is available.
- Keeping throwaway scaffolding experiment tests in the package test suite.
- Finishing a task without a green `composer test`, or running tests any other way than `composer test` and `vendor/bin/pest`.
