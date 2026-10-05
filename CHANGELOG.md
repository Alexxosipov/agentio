# Release Notes

## [Unreleased](https://github.com/Alexxosipov/agentio/compare/v0.4.0...main)

## [v0.4.0](https://github.com/Alexxosipov/agentio/releases/tag/v0.4.0) - 2026-10-05

Update with `composer require obrazmisli/agentio:^0.4 --dev`, then run `php artisan agentio:install` (refreshes the skills) and commit `.claude/skills/agentio-*` to the development branch together with `composer.json` and `composer.lock`. Make sure the project's `composer.json` has a `test` script — the full quality gate the agents now run at the end of every task; move whatever `AGENTIO_TEST_COMMAND` and `AGENTIO_FULL_TEST_COMMAND` did into it and delete them from `.env` (and the `tests` key from a published `config/agentio.php`). `storage/logs/tests/` is no longer written and can be deleted.

### Removed

- `php artisan agentio:test`, its `scripts/run-tests.sh` and `scripts/php/testing.ini`, and the `tests.command` / `tests.full_command` settings (`AGENTIO_TEST_COMMAND`, `AGENTIO_FULL_TEST_COMMAND`). Projects that relied on its `zend.assertions=1` set it in php.ini or in their `test` script.

### Changed

- The agents run tests only through the project's own tools: `composer test` (the full quality gate) at the end of every task, by the developer, the story reviewer and the epic orchestrator, and `vendor/bin/pest <files> [--filter=…]` for specific tests while they work. The rule is a new «Тесты» section of `agentio-youtrack-workflow`, the skills allow `composer test` and `vendor/bin/pest` instead of `agentio:test`, and the task, comment, ADR-001 and acceptance templates name `composer test`.

### Fixed

- The test suite parses on PHP 8.3 again (a test used PHP 8.4's `new Foo()->bar()`, and the PHP 8.3 lanes of CI failed in the linter before running the tests); Pint's `new_expression_parentheses` rule keeps the parentheses.

## [v0.3.0](https://github.com/Alexxosipov/agentio/releases/tag/v0.3.0) - 2026-10-05

Update with `composer require obrazmisli/agentio:^0.3 --dev`, then run `php artisan agentio:install` (refreshes the skills) and commit `.claude/skills/agentio-*` to the development branch together with `composer.json` and `composer.lock`. ADR-001 of an existing knowledge base is not rewritten: the architect adds the code structure rule to it on the next epic.

### Changed

- The code is laid out by domain namespaces: the architect puts every class into `App\<Domain>\<Kind>\<Class>` (a domain per module of the analysis, `App\Shared\…` for code of several domains) instead of flat `App\Actions`, `App\Models`, `App\Services`, `App\Http\Controllers` and the like; migrations, factories, seeders, `config`, `routes`, `lang` and the application skeleton (`App\Providers`) stay flat. Every test mirrors the namespace of the class it tests (`App\Users\Actions\X` → `tests/Unit/Users/Actions/XTest.php`). The new `code-structure.md` reference of the architect skill describes the layout, the Laravel wiring it needs (factories, policies, listeners, commands, morph map, generators) and how flat code is moved; the rule goes into ADR-001, the «Компоненты» table of a feature lists full class names with their test paths, the developer creates classes by their full names and the reviewer flags flat classes and tests that do not mirror their class.

## [v0.2.0](https://github.com/Alexxosipov/agentio/releases/tag/v0.2.0) - 2026-10-05

Update with `composer require obrazmisli/agentio:^0.2 --dev`, then run `php artisan agentio:install` (refreshes the skills) and `php artisan agentio:setup-youtrack` (cleans Stage up, checks the board, refreshes the guide), and commit `.claude/skills/agentio-*` and `.agentio.json` to the development branch together with `composer.json` and `composer.lock`.

### Changed

- The architecture lives in the knowledge base next to the requirements: the architect writes the decisions of a feature, with their reasons and rejected options (`AD-n`), into an «Архитектура» section of the feature article and the decisions of a module into «Архитектура модуля» of the module article; ADRs are kept for project-wide decisions, «Архитектура: обзор» maps the modules to the code, «Модель данных» indexes the tables by their feature articles. No «Эпик <ID>: …» article is created any more: the implementation order goes into the epic description, and the epic articles of earlier versions are a read-only archive the architect moves into feature and module articles. The `epic-design-template.md` reference of the architect skill is replaced by `architecture-sections.md`.
- The system analyst and the architect load the `laravel-best-practices` skill of Laravel Boost and follow its rule files. The analyst goes through the behaviour of the system for every feature (long operations, retries, concurrency, failures of external systems, money, notifications, data, volumes, time) with the new `system-aspects.md` reference and records it in a new «Особенности системы» section; the architect has a reliability checklist and new references: `queues-and-jobs.md` (what goes to a queue, job design), `idempotency-and-consistency.md`, `payments.md` (minor units, payment state machine, provider idempotency keys, webhooks, reconciliation, ledger, refunds), `external-integrations.md` (adapters, timeouts, retries, circuit breaking, webhooks) and `operations.md` (observability, cache, files, security, zero-downtime migrations). `agentio:install` reports a project without the skill.
- Questions to the human follow one protocol (the «Вопросы к человеку» section of the workflow skill): the agent finishes and records what does not depend on the answers, asks all its questions in one `[AGENT:BLOCKED]` of the issue it works on (the idea while planning) with numbered questions, options and its recommendation, and names the Stage to return the issue to. The human answers with a comment in that issue (`В1: б; В2: а`, or `Принимаю рекомендации`) and returns the Stage; returning it without a comment accepts the recommendations. The resumed agent moves the answers into the knowledge base and records an `[AGENT:DECISION]`.
- `agentio:setup-youtrack` deletes the values YouTrack gives Stage that the cycle does not use (Develop, Test, Staging) from the project's own bundle; a value issues of the project are in is kept with a warning.
- `agentio:setup-youtrack` checks that the project has an agile board with columns by Stage and swimlanes by Type and exits with code 3 when it has none (the rest of the setup is done; `agentio:install` still installs the skills and fails at the end); a fitting board without a column for a stage gets a warning. The board is never created or changed.
- Planning sessions may run `php artisan list`; the workflow skill says that the Bash tool reports the exit code itself (`; echo "exit=$?"` was denied).

### Fixed

- Epic worktrees failed on `composer install` when the development branch still installed agentio from `packages/agentio` (a project that moved to the Composer package on another branch only), and the loop retried every pass. `agentio:run` now refuses to start while the development branch installs a package from a path it does not have, `agentio:worktree` explains it for an epic branch made before the fix, and `loop.log` shows the reason of a failed worktree setup.
- `agentio:yt validate` reported an epic that waits for another epic as having an empty first wave; only the dependencies inside the epic count for the first wave now.

## [v0.1.0](https://github.com/Alexxosipov/agentio/releases/tag/v0.1.0) - 2026-10-05

The first release on GitHub: Composer installs the package from the repository (a `vcs` repository in `composer.json`, see the README), so a project no longer needs a copy of it. The notes below compare it with the copies of the package installed by hand before.

### Changed

- Branch model: `main` is production, `dev` the development branch (the develop server); every epic is worked on in a branch named after its issue id (`TP-12`) instead of `epic/<ID>-<slug>`, started from `dev` and merged back into it. `agentio:install` asks for both branches and creates the missing ones locally. Epics started on an `epic/<ID>-<slug>` branch are resumed and accepted on it. `AGENTIO_BASE_BRANCH` now defaults to `dev`; `agentio:yt slug` and the branch slugs are gone.
- The structure rules of `agentio:yt validate` live in `Process\StructureValidator`; `IssueType` knows its summary prefix and its parent type.
- The settings of the headless sessions are built in PHP (`SessionSettings`) and allow pushing only the branches of the project's issues; the loop no longer runs inline PHP for them.
- `agentio:install` adds only skills to the project (`.claude/skills/agentio-*`, packaged as Claude Code skills with the `agentio-` prefix; the subagents became the `agentio-develop-task` and `agentio-review-story` skills) plus `.agentio.json` and `.env` keys. Scripts, hooks, session settings, MCP configs and the manual stay in the package; nothing is merged into `.claude/settings.json`, `.mcp.json`, `CLAUDE.md`, `.gitignore` or `.env.example`.
- The agents work with YouTrack only through its MCP server. `scripts/yt.php` is replaced by `php artisan agentio:yt`, which also talks to the MCP server; the REST API is left to the project setup and the dashboard.
- The loop scripts run only through artisan: `agentio:run`, `agentio:worktree`, `agentio:commit`, `agentio:test` and `agentio:log`.
- The status of an issue is the **Stage** field, the status field YouTrack gives new projects; `agentio:setup-youtrack` adds the cycle's values to its bundle. A `State` field («Состояние») is no longer used: the setup reports it and leaves it as is.
- `agentio:install` checks the token through the MCP server, adds the `youtrack` MCP server to Claude Code with `claude mcp add` when it has none, and asks for the directory of the epic worktrees (`AGENTIO_WORKTREES_PATH`, required; there is no default any more).
- The merge policy is recorded in `.agentio.json` instead of a `MERGE_POLICY:` line of `CLAUDE.md`; the stop flag lives in the logs directory.

### Added

- Releases: every `v*` tag pushed to GitHub runs the test suite and publishes a GitHub release with the notes of its version from this changelog (`.github/workflows/release.yml`).
- The epic orchestrator merges the development branch into the epic branch before the full test run (a conflict is aborted and left for the human, with the files listed), so an epic is tested together with the epics accepted after it started.
- `AGENTIO_PRODUCTION_BRANCH` (`production_branch`, recorded in `.agentio.json`).
- Planning sessions run with read-only settings (`resources/claude/planning.json`): they work in the developer's main checkout and change no file.
- `php artisan agentio:accept <EPIC>`: the dashboard's acceptance from the command line. The loop uses it for `auto-merge` without a remote, so an auto-merged epic and its stories are now moved to `Done` too.
- Epic acceptance in the dashboard: the epic page shows the commits, changed files and per-file diffs of the epic branch, the merge checks, the final `[AGENT:DONE]` and the story verdicts; an epic in `Review` can be accepted (merge into the base branch, worktree and branch removal, stories and epic to `Done`) or sent back for rework (a TASK with the remark, the story and the epic back to `Ready`). `AGENTIO_UI_ACTIONS=false` makes the dashboard read-only.
- `agentio:setup-youtrack` configures the YouTrack project on its own and can run any number of times without creating anything twice. It uses the project's Stage and Type fields, found by name or localized name.

### Fixed

- A YouTrack in another language broke the cycle: the MCP server answers with the localized name of a value («Очередь» instead of `Backlog`). `agentio:setup-youtrack` now gives the cycle's values their English names in the project's own bundles — it removes their localized name, renames a value named in Russian («Очередь», «Задача», …) instead of adding a second one, and renames a Stage or Type field named in Russian («Этап», «Тип») instead of only warning about it.
- `agentio:log` reads a log from its end instead of loading it whole, and `--follow` prints whole lines only and starts over when the log is truncated.
- YouTrack writes (creating an issue, posting a comment, creating a tag or a saved search) are no longer sent again after a timeout or a 5xx, which could duplicate them; reads still are, and writes are retried after a refused connection or a 429 (`YouTrack\Retry`, shared by the REST and the MCP client).
- An HTTP error of the MCP endpoint (a wrong URL) is a transport error, no longer "issue not found"; a session the MCP server dropped is opened again once.
- The dashboard's actions need the new `manageAgentio` gate (by default the same rule as `viewAgentio`) and a session, so they never run without a CSRF check; the review panel shows them only to users who may use them.
- The session card of a resumed epic no longer shows the result of the previous run.
- `AGENTIO_UI_CACHE` defaults to 15 seconds: with 5 (the poll interval) the cache almost never answered.
- A planning session that broke off no longer leaves its idea claimed in `Analysis` for good: `agentio:yt resumable` lists the epics and the ideas this machine left unfinished, and the loop resumes both (the resume rule moved from bash to PHP). Planning no longer blocks the loop: it is reaped like an epic session, and a finished session starts the next pass right away.
- Restart accounting: a session that ended unfinished counts also when it died before claiming its epic (it was relaunched forever), new commits on the epic branch reset the count (a long epic that moves is no longer blocked), and ideas are counted too. `--kill` stops planning sessions as well.
- A session counts as alive only while its pid is a Claude Code process (a pid reused after a reboot held a slot forever); `loop.lock` keeps a second loop from starting.
- `agentio:yt claim` withdraws a claim that failed half-way, and `release` always ends the claim in the comments, so the tag and the comment chain no longer disagree.
- `agentio:test` runs the tests in their own process group and kills only what that run left behind, not the Playwright servers of a parallel run in the same worktree.
- The worktree `.env` is written with the package's `EnvFile` (a worktrees path with a space broke it); the MCP configs are passed one per line.
- The guard hook is `bin/agentio-guard`, run from the package of the main checkout without booting the worktree's application; it refuses the command when it fails, and takes the protected branches and the extra directories from the loop instead of the worktree's `.claude/settings.json` (an agent could widen them, and `/` let every path through). `agentio:guard` is removed.
- The guard splits command lines like a shell and closes the bypasses found in an audit: artisan options before the command (`php artisan -n tinker`), `agentio:run --dry-run --kill/--stop`, git aliases and `git -c`/`config`, combined short flags (`git push -fu`), `cd` before a relative path, `cp`/`sed -i`/`>` onto the agentio settings, `find -exec`, `php -i`, `/proc/$$/environ`, `.en?`; and it no longer refuses a plan or a commit message that merely mentions `.env`.
- `Bash(php -i)` is no longer allowed (it printed the token), and the `tinker` and `get-config` tools of Laravel Boost are denied.
- The guard no longer takes the branch of the repository it runs in when the directory of the command does not exist.
- The scripts of the package run through `bash`, so they work when Composer unpacks a release without the executable bit.
- `agentio:run --dry-run` cannot be combined with `--stop` or `--kill`.
- Accepting an epic merges `refs/heads/<branch>`: a tag named like the branch no longer wins.
- An epic branch without commits of its own is no longer accepted as "already merged".
- Acceptance runs under a lock of the repository (shared by the dashboard and the loop) instead of the cache lock, and a merge that fails or times out is always aborted.
- The dashboard's git calls take no optional locks, so its polling no longer makes commits of agents and humans fail on `index.lock`.

### Removed

- Dead code: the unused queries of `IssueRepository`, `ReadinessGraph::waitingForDependencies()`, `AgentComment::isAgentComment()`, `Issue::isResolved()`, `LoopState::epicLogPath()` / `planLogPath()` and `Client::detachProjectCustomField()`.
- `sync-stage`: Stage is the status field itself, nothing is derived from another field.
