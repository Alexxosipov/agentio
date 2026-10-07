# Release Notes

## [Unreleased](https://github.com/Alexxosipov/agentio/compare/v0.10.0...main)

## [v0.10.0](https://github.com/Alexxosipov/agentio/releases/tag/v0.10.0) - 2026-10-08

Update with `composer require alexxosipov/agentio:^0.10 --dev`, run `php artisan agentio:install` (refreshes the skills), commit `.claude/skills/agentio-*` to the development branch together with `composer.json` and `composer.lock`, and restart `agentio:run`. Drop `AGENTIO_INTERVAL` from `.env` if it pins the old 300 seconds. The «Модель данных» article under «Архитектура» is no longer maintained: the analyst moves its entities into the data model article of each module as the modules are touched, and the article stays as an archive. Code already laid out as `app/<Domain>/…` is not moved: new classes go into the Laravel directories, and moving the old ones is your decision.

### Added

- **A data model per module.** Every module of «Системная аналитика» gets a child article «Модель данных: <Модуль>»: the entities of the module (attributes, relations, lifecycle, personal data), owned by the analyst, and their tables in the «Таблицы» section (columns, types, indexes, keys), owned by the architect. Each entity is described once, in the data model of its module; a feature article says what the feature does with it in its «Данные» section; the module article, the module map and «Архитектура: обзор» link the data model. A large schema no longer grows into one article.
- **The execution log of an issue on the Kanban board.** A click on a card (or Enter) opens a dialog with the log of the issue and a link to it in YouTrack (in a new tab), refreshed while it is open: an idea shows its planning session, an epic its epic session, a story or a task the events of its epic session that mention it or its subtasks (`GET /agentio/api/issues/{id}/log`, the token redacted as in the other endpoints).

### Changed

- **The code follows the Laravel structure, grouped by domain inside it.** The default layout of the architect, developer and reviewer skills and of ADR-001 is `App\<Kind directory>\<Domain>\<Class>` — `App\Actions\Listings\PublishListing`, `App\Http\Controllers\Auth\LoginController`, `App\Models\Articles\Article`, `App\Services\Listings\…` — with mirrored tests (`tests/Feature/Actions/Listings/PublishListingTest.php`), instead of top-level domain namespaces (`App\Users\Actions\…`, `App\Shared\…`). Factories and policies follow the subdirectory of their model and are discovered by Laravel without attributes; `App\Models\User` stays where Laravel puts it. A layout written down by the project (`CLAUDE.md`/`AGENTS.md`, `.ai/guidelines`, `.ai/rules`) still comes first.
- The loop makes a pass every 60 seconds by default instead of 300 (`AGENTIO_INTERVAL`), so a new idea or a ready epic is picked up within a minute.

### Fixed

- An idea created through the Telegram bot ended its reply with «⚠️ Не удалось записать в YouTrack: YouTrack MCP create_issue returned no issue id.», and the idea got no `idea` tag: the id of the created issue is now read from the `createdIssue` object the YouTrack MCP server answers with (the same for `createdArticle`). Reworking an epic from the dashboard had the same failure.
- The Kanban board stayed empty when the browser kept an epic filter of another project served on the same origin: board filters are now stored per project, and a filter for an epic the project does not have is dropped.

### Removed

- The «Модель данных» article under «Архитектура» from the knowledge base `agentio:setup-youtrack` creates, and the `{{kb.architecture.data_model}}` placeholder.

## [v0.9.0](https://github.com/Alexxosipov/agentio/releases/tag/v0.9.0) - 2026-10-08

Update with `composer require alexxosipov/agentio:^0.9 --dev`. Install the [GitHub CLI](https://cli.github.com) and run `gh auth login` first (`agentio:install` and `agentio:run` no longer start without it), make sure the repository has its GitHub `origin` with `main` and `dev` pushed, then run `php artisan agentio:install` (refreshes the skills and drops `merge_policy` from `.agentio.json`) and `php artisan agentio:setup-youtrack` (refreshes the automation guide), commit `.claude/skills/agentio-*` and `.agentio.json` to the development branch together with `composer.json` and `composer.lock`, and restart `agentio:run`. Ideas, epics and tasks reported by other YouTrack users are no longer taken: file them as the user of `YOUTRACK_TOKEN` (or change their reporter). An epic already in `Review` gets its pull request when you accept it (or run `php artisan agentio:pr <ID>`).

### Added

- **Epics are accepted only through GitHub pull requests.** `php artisan agentio:pr <EPIC>` pushes the epic branch to `origin` (never forced) and opens its pull request into the development branch, or updates the open one after rework; the orchestrator runs it when the epic is done and the loop once more when the epic reaches `Review`, writes the link to `loop.log` and sends it to the Telegram bot. The tasks of the stories keep going into the epic branch locally and automatically, without branches or pull requests of their own.
- **Accepting merges the pull request.** The dashboard's «Принять и слить», `php artisan agentio:accept <ID>` and the Telegram bot push the branch (opening the pull request when there is none) and merge the pull request into the development branch on GitHub with a merge commit, only while its head is the local tip of the epic branch (`gh api …/pulls/<n>/merge` with the head sha); then they fast-forward the local development branch, remove the worktree and close the stories and the epic with a comment that links the pull request. A draft or conflicting pull request is refused with the reason; a pull request merged on GitHub by hand is detected, and accepting only cleans up and closes. The dashboard shows the pull request of the epic and its merge checks (GitHub CLI, pull request, conflicts).
- **Merging from Telegram.** «смержи TP-12» (or «мержи» as a reply to the report of a finished epic) accepts the epic the same way; it never creates a YouTrack issue. The «epic ready for review» report carries the link to the pull request.
- **Releases through a confirmed pull request.** «вмержи dev в main» to the bot opens the pull request of the development branch into the production branch (or finds the open one), sends the link and asks to confirm; only «да» to that question merges it, and only at the commit of `dev` the question was about (when `dev` moved, the bot asks again). `php artisan agentio:release [--merge] [--yes]` does the same from the command line. Releases are not YouTrack issues either.
- **The agents work only on the issues of the agentio user.** `agentio:yt ideas`, `ready-epics`, `ready-tasks` and `resumable` offer only the issues reported by the YouTrack user of the token (`reporter: me`), and `agentio:yt claim` answers `LOST` (exit 3) for an issue another user reported. `php artisan agentio:yt mine <ID>` tells `MINE` or `FOREIGN`; `agentio:install` says which user the agents work as.
- `agentio:install` checks the GitHub CLI before anything else and stops, changing nothing, when `gh` is missing or not logged in (`gh auth status`); `git remote origin` is a new precondition. `agentio:run` refuses to start without a logged-in `gh` (except `--dry-run` and `--kill`).
- New epic worktrees start from `origin/<dev>` when GitHub has epics the local development branch lacks.

### Changed

- The orchestrator (`agentio-work-epic`) brings `origin/<dev>` into the epic branch before the full test run and publishes the epic with `php artisan agentio:pr`; the workflow, project manager, status and Telegram assistant skills, the «Процесс разработки» article and the manual describe the pull request flow, the releases and the issue ownership rule.
- The agents only read GitHub: the guard refuses every `gh` command but `gh pr view|list|diff|checks|status`, `gh run view|list`, `gh repo view` and `gh auth status`, and the session settings deny `gh pr merge`, `gh pr create`, `gh api` and `agentio:release`. The assistant of the bot may read pull requests.

### Removed

- **The merge policies** (`local-branch`, `pull-request`, `auto-merge`): `agentio:install --merge-policy`, `AGENTIO_MERGE_POLICY`, the `merge_policy` config key, `Settings::mergePolicy()`, `Runtime\MergePolicy`, the `{{merge_policy}}` placeholder and the `merge_policy` of `.agentio.json` (ignored when present). Epics are never merged locally or automatically any more, and the loop no longer accepts epics by itself. The `merged` event of `agentio:telegram notify` is gone; `notify review` takes `--url=`.
- `mergePolicy` of the dashboard's `/api/status` and `/api/epics/{id}/review` (the review has `pullRequest` instead), and the `checkout` and `clean` checks of an acceptance (the main checkout is no longer merged into).

## [v0.8.0](https://github.com/Alexxosipov/agentio/releases/tag/v0.8.0) - 2026-10-07

Update with `composer require alexxosipov/agentio:^0.8 --dev`, then run `php artisan agentio:install` (refreshes the skills: the orchestrator no longer blocks a task whose subagent ran into the usage limit) and commit `.claude/skills/agentio-*` to the development branch together with `composer.json` and `composer.lock`, then restart `agentio:run`. An epic or idea that an earlier version moved to `Blocked` because of the limit («сессия … 3 раза подряд завершилась…») is returned by hand: set its Stage back to `Ready` (an idea to `Backlog`).

### Added

- **The loop waits out the usage limit of Claude Code.** A session that ends at the five-hour or weekly limit of the subscription (a `rejected` `rate_limit_event` or a `rate_limit` error in its log) is no longer a failure: it is not counted in `<NAME>.restarts`, nothing gets `[AGENT:BLOCKED]`, the issue keeps its state and claim. The loop pauses — starts no epic or planning session and does not call YouTrack — until a minute after the reset Claude Code reports (`AGENTIO_LIMIT_RETRY` seconds, 900 by default, when it reports none), then resumes the issues it claimed. The pause is kept in `storage/logs/agents/limit` (it survives a restart of `agentio:run`; delete the file to resume earlier) and shown by `agentio:status`, `agentio:run --dry-run` and the dashboard header.
- The Telegram bot reports the pause («⏸ Claude Code упёрся в 5-часовой лимит … Продолжу автоматически в 18:41») and the resumed work (`agentio:telegram notify limit|resumed`; the issue id is optional for these events, `--until=` and `--window=` describe the limit).
- `php artisan agentio:limit <ID>`: whether the latest run of a session ended at the usage limit, and when the loop resumes.

### Changed

- `agentio-work-epic`: a subagent cut off by the usage limit is neither restarted nor blocked; the orchestrator ends the session and the loop resumes the epic after the reset.

### Fixed

- The loop no longer starts a session again in the same pass when it ended before the loop reaped it (the next reap judges it first): at the limit, such restarts used up the attempts within seconds.

## [v0.7.0](https://github.com/Alexxosipov/agentio/releases/tag/v0.7.0) - 2026-10-06

Update with `composer require alexxosipov/agentio:^0.7 --dev`, then run `php artisan agentio:install` (refreshes the skills and adds the local services: a project on SQLite is switched to PostgreSQL in `.env`, `.env.example` and `phpunit.xml`; pass `--skip-services` to keep your database setup), start the services with `docker compose up -d`, run `php artisan migrate`, and commit `.claude/skills/agentio-*`, `.agentio.json`, `compose.yaml`, `docker/`, `phpunit.xml` and `.env.example` to the development branch together with `composer.json` and `composer.lock`. Restart `agentio:run` so that the agents' tests run on their own databases. ADR-001 of an existing knowledge base is not rewritten: the architect adds the «Интеграции» rule to it on the next epic.

### Added

- **Local services.** `agentio:install` adds `compose.yaml` (PostgreSQL 18 and Redis 7 with volumes and health checks, credentials from `.env`) and `docker/postgres/initdb/01-create-testing-db.sh` (creates the `testing` database of the tests), switches `.env` and `.env.example` of a project on SQLite to PostgreSQL (`DB_*`; `REDIS_HOST`/`REDIS_PORT` when missing; the commented-out keys of a new Laravel project are set in place) and points `phpunit.xml` (or `phpunit.xml.dist`) at the `testing` database (`DB_CONNECTION=pgsql`, `DB_DATABASE=testing`, `DB_URL=`). A project already on PostgreSQL keeps its values, a project on another database is left alone with a warning, an existing `compose.yaml` is kept unless `--force`; `--skip-services` skips the step. The preconditions check Docker.
- **Isolated test runs of the agents.** The loop sets `AGENTIO_ISOLATED_TESTS=1` for its sessions, and the service provider of agentio then makes every test process a parallel-testing process of Laravel with a token of its own (`agentio_<slot>`, a slot of the machine locked while the process runs): parallel epics and the tasks of a wave each get their own test database (`testing_test_agentio_<slot>`, created and migrated by Laravel), cache and compiled views instead of recreating the tables under each other. Tests on an in-memory SQLite database are not affected.

### Changed

- The integration rule of the skills is spelled out: an external API is called through the service's maintained SDK when it has one (installing it is the human's decision) and through a client written on Saloon when it has none — never raw `Http::`, Guzzle or curl — and **every integration lives in `App\Integrations\<Service>`** (`app/Integrations/<Service>`, tests in `tests/Feature/Integrations/<Service>`) whatever the layout of the rest of the code. `agentio-saloon`, the architect's `external-integrations.md` and `code-structure.md`, the reviewer, the platform skill, the ADR-001 template, the Boost guideline and the manual say so.
- The Telegram bot and the speech-to-text driver of the package call the Telegram Bot API and the OpenAI-compatible API with the HTTP client of Laravel. `EnvFile` sets a commented-out key on its line instead of appending it.

### Removed

- The dependency on `saloonphp/saloon` (the package depends on no SDK and no HTTP client library of its own) and the Saloon classes of the bot: `Telegram\Api\TelegramConnector`, `TelegramFileConnector`, the `Telegram\Api\Requests\*` requests, `Telegram\Transcription\TranscriptionConnector` and `CreateTranscription`. `Bot::make($token, $apiUrl)` and `OpenAiTranscriber` keep working; `new OpenAiTranscriber(...)` now takes the URL and the key instead of a connector. Tests of an application that faked the bot with Saloon's `MockClient` fake it with `Http::fake()`.

## [v0.6.0](https://github.com/Alexxosipov/agentio/releases/tag/v0.6.0) - 2026-10-06

Update with `composer require alexxosipov/agentio:^0.6 --dev`, then run `php artisan agentio:install` (refreshes the skills, adds `agentio-saloon`, `agentio-platform-skill` and `agentio-telegram-assistant`, and offers the Telegram bot) and `php artisan agentio:setup-youtrack` (refreshes the automation guide), and commit `.claude/skills/agentio-*` and `.agentio.json` to the development branch together with `composer.json` and `composer.lock`. ADR-001 of an existing knowledge base is not rewritten: the architect brings its «Структура кода» to the new rule on the next epic. With Laravel Boost, choose `alexxosipov/agentio` in `php artisan boost:install` to get the agentio guideline in `CLAUDE.md`.

### Added

- **The developer's Telegram bot**, a project manager in a chat (optional; the cycle never depends on it). It sends the questions of the agents (every new `[AGENT:BLOCKED]`) and short reports — an idea planned, an epic ready for review or merged, a session that gave up — and answers text and voice messages: a reply to a question becomes a comment of its issue («Ответ разработчика (Telegram)…») and, when it answers every question, returns the issue to the Stage its `[AGENT:BLOCKED]` names; a described feature becomes an idea for the loop; questions about the project are answered from YouTrack, the loop and the code. The messages are read by a read-only headless Claude Code session with the new `agentio-telegram-assistant` skill (`resources/claude/assistant.json`); agentio makes the YouTrack changes it decides on.
- `php artisan agentio:setup-telegram <token>`: checks the token, writes it to `.env` (`AGENTIO_TELEGRAM_BOT_TOKEN`), sets up voice messages — an OpenAI-compatible `/audio/transcriptions` API or the local whisper.cpp CLI with ffmpeg (`AGENTIO_TRANSCRIPTION_*`, `AGENTIO_WHISPER_*`, `AGENTIO_FFMPEG_BIN`; more drivers through `TranscriptionManager::extend()`) — installs Laravel Horizon when the project has none (with `predis/predis` when PHP has no redis extension), restarts what still uses the old settings (`config:cache`, `horizon:terminate`) and pairs the developer's chat with `/start <code>` (`AGENTIO_TELEGRAM_CHAT_ID`). `agentio:install` offers it (`--telegram-token=`, `--skip-telegram`).
- `php artisan agentio:telegram listen|notify|assist|send|status`. `agentio:run` starts the listener (long polling with `getUpdates`, written by the package itself on [Saloon](https://docs.saloon.dev), no Telegram SDK) and, when the project's Horizon is not running, Horizon, each in a process of its own next to the loop; it restarts them when they end and whenever a key of `.env` they use changes (`--no-telegram` turns the bot off). Every message goes through the `default` queue of the `redis` connection; the loop reports its events with `agentio:telegram notify` in the background.
- The `agentio-saloon` skill: integrations with HTTP APIs without a maintained SDK through Saloon v4 (connector, requests, auth, errors and retries, DTOs, pagination, rate limits, OAuth2, tests with `MockClient`). Saloon is the one dependency the agents may add themselves: the guard allows `composer require saloonphp/*` (not `--dev`) and refuses any other package.
- The `agentio-platform-skill` skill: when an epic brings the product to an external platform (Telegram Mini App, Telegram bot, VK Mini App, PWA, a payment provider…) without a skill in the project, the architect plans a first task that writes `.claude/skills/<platform>-development` from the official documentation (the sessions may search the web and read the documentation sites of these platforms).
- `resources/boost/guidelines/core.md`, the agentio guideline for Laravel Boost.

### Changed

- The skills follow Laravel Boost and the project's conventions: a new «Laravel Boost и конвенции проекта» section of `agentio-youtrack-workflow` sets the priority (agentio process, project decisions, project conventions — `.ai/guidelines`, `.ai/rules`, the existing code —, Boost, agentio defaults), says how an autonomous session treats "ask the user", which Boost tools to use and which skills to load. The domain namespaces are now the default only for a project without a layout of its own (a project that keeps actions in `app/Actions` keeps doing so; changing the layout is a question to the human), tests go to `Feature` or `Unit` by Boost's `testing-best-practices`, Pint runs with `--format agent` on the agent's own files, Wayfinder routes are regenerated in a fresh worktree, new dependencies are the human's decision.
- The questions to the human are written only in Russian, and the developer may answer them through the Telegram bot.
- Session permissions: `php artisan test`, `php artisan config:show`, `boost:*`, the `record-rule` tool of Boost and edits of the files Boost generates are denied; the tools Boost 2 no longer has (`list-routes`, `list-artisan-commands`, `get-config`) are gone from the rules; `vendor/bin/phpunit` is no longer allowed; web search and the documentation sites of the supported platforms are allowed.

- `php artisan about` shows an «Agentio» section (version, YouTrack, the bot, voice messages, the queue; no secrets). `composer.json` suggests `laravel/horizon`, `ext-redis`/`predis/predis` and `ext-pcntl`.

### Fixed

- `.env` is written atomically (a symlinked `.env` keeps its link); a new `.env` is readable by its owner only.

## [v0.5.0](https://github.com/Alexxosipov/agentio/releases/tag/v0.5.0) - 2026-10-05

The package is renamed to `alexxosipov/agentio`. Composer no longer finds `obrazmisli/agentio` in the repository, so switch with `composer remove obrazmisli/agentio --dev && composer require alexxosipov/agentio:^0.5 --dev` (the `vcs` repository entry stays the same), then run `php artisan agentio:install` and `php artisan agentio:setup-youtrack` (refreshes the automation guide, which names the new `vendor/alexxosipov/agentio` path) and commit `composer.json` and `composer.lock` to the development branch. The PHP namespace stays `Obrazmisli\Agentio`, so a service provider that calls `Agentio::auth()` needs no change.

### Changed

- The Composer package is `alexxosipov/agentio` instead of `obrazmisli/agentio`: the README, the automation guide (the package now lives in `vendor/alexxosipov/agentio`), the install command and the MCP client name follow it.

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
