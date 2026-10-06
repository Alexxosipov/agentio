<div align="center">
    <h1>Agentio</h1>
</div>

<p align="center">
    <a href="https://github.com/Alexxosipov/agentio/releases"><img src="https://img.shields.io/github/v/release/Alexxosipov/agentio?style=flat-square&label=release" alt="Latest release"></a>
    <a href="https://github.com/Alexxosipov/agentio/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/Alexxosipov/agentio/tests.yml?branch=main&label=tests&style=flat-square" alt="Tests"></a>
    <a href="LICENSE.md"><img src="https://img.shields.io/github/license/Alexxosipov/agentio?style=flat-square" alt="License"></a>
</p>

Autonomous agentic development cycle for Laravel: YouTrack as the source of truth, Claude Code as PM, analyst, architect, developer and reviewer.

A human files an idea in YouTrack and accepts the result. Everything in between is done by Claude Code agents: requirements (project manager), system analysis kept per product module and feature (analyst), the design of each epic (architect), stories and tasks with dependencies, code and tests (developer subagents, in parallel when files do not overlap), story reviews (reviewer) and a full test run. Every epic is worked on in its own git worktree and in a branch named after its issue id (`TP-12`), started from the development branch `dev` and merged back into it; `main` is production. YouTrack is the only source of truth, so work resumes from any point after a crash.

The agents work with YouTrack only through its **MCP server**. The package adds only **skills** to the project (`.claude/skills/agentio-*`); the loop scripts, the settings of the agents' sessions and the manual stay in the package and run through `php artisan agentio:*`. It also configures the YouTrack project, runs the loop and shows its state in the terminal and in a dashboard.

## Requirements

- Linux or macOS (the loop runs bash scripts under `setsid`; Windows is not supported).
- PHP 8.3+ with `mbstring` (`posix` and `pcntl` recommended), Laravel 12 or 13.
- git, Composer, `setsid` (util-linux; on macOS `brew install util-linux`), bun or npm when the project has a frontend build.
- [Claude Code](https://docs.claude.com/claude-code), logged in.
- A YouTrack instance with its MCP server (`<url>/mcp`, YouTrack Cloud or a Server version that has it) and a permanent token.
- Optional: Docker with the compose plugin for the [local services](#local-services) (PostgreSQL and Redis) `agentio:install` adds.
- Optional, for the [Telegram bot](#telegram-bot): Redis and Laravel Horizon (`agentio:setup-telegram` installs Horizon), and for voice messages an OpenAI-compatible speech-to-text API or [whisper.cpp](https://github.com/ggml-org/whisper.cpp) with ffmpeg.

`php artisan agentio:install` checks all of this and tells you what is missing.

The project also needs a `test` script in its `composer.json` (the full quality gate: tests, linters, static analysis — whatever the project uses): the agents finish every task with `composer test` and run single tests with `vendor/bin/pest`.

## Quick start

1. Install the package from GitHub as a dev dependency (see [Installation](#installation): add the repository to `composer.json`, then `composer require alexxosipov/agentio:^0.7 --dev`).
2. Install the cycle into the project (a git repository whose `.env` is git-ignored): `php artisan agentio:install`. It asks for the YouTrack URL and a permanent token (hidden input), checks them through the YouTrack MCP server, adds the `youtrack` MCP server to Claude Code if it has none, asks for the project short name (`ABC`; a missing project can be created), the production and the development branch (`main` and `dev`; the missing one is created locally), the merge policy and **the directory of the epic worktrees**, offers to configure the YouTrack project, installs the skills and the [local services](#local-services) (`compose.yaml` with PostgreSQL and Redis; `.env` and `phpunit.xml` point at them) and offers to connect your own [Telegram bot](#telegram-bot).
3. Start the services and migrate: `docker compose up -d && php artisan migrate`. Commit `.claude/skills/agentio-*`, `.agentio.json`, `compose.yaml`, `docker/`, `phpunit.xml` and `.env.example` to `dev`: epic worktrees are created from it. `.env` (with the token) stays local. Push `dev` and `main` if the project has a remote, and point the develop server at `dev` and production at `main`.
4. File an idea in YouTrack (Type `Idea` or the `idea` tag, Stage `Backlog`), check what the loop would do with `php artisan agentio:run --dry-run`, then start it: `php artisan agentio:run`.
5. Watch the agents at `/agentio` or with `php artisan agentio:status`, answer the questions of `[AGENT:BLOCKED]` comments (a comment in the issue, then the Stage back; see [Questions to the human](#questions-to-the-human)), and accept epics that reach `Review` on their page in the dashboard or with `php artisan agentio:accept <ID>` (it merges the epic branch into `dev`). Release `dev` to `main` yourself when the develop server looks good.

## Installation

The package is not on Packagist: Composer installs it from the [GitHub repository](https://github.com/Alexxosipov/agentio), and every release is a git tag (`v0.1.0`, `v0.2.0`, …). Add the repository to the host project's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/Alexxosipov/agentio" }
]
```

or with one command:

```bash
composer config repositories.agentio vcs https://github.com/Alexxosipov/agentio
```

Then require it:

```bash
composer require alexxosipov/agentio:^0.7 --dev
```

Install it as a **dev dependency**: the cycle runs on a developer machine (Claude Code, git worktrees, the loop) and nothing of it is needed in production; it also keeps the dashboard, which shows YouTrack data and agent logs, out of production builds. Require it without `--dev` only if you want the dashboard on a shared or staging server. If you call `Agentio::auth()` from a service provider, guard it with `class_exists(Agentio::class)` so that `composer install --no-dev` keeps working.

Composer reads the tags through the GitHub API. Without a token it is limited to 60 requests an hour, and on a busy machine or CI it may ask for one: create a token without scopes at https://github.com/settings/tokens and run `composer config --global github-oauth.github.com <token>`.

### Updating

```bash
composer update alexxosipov/agentio
php artisan agentio:install           # refreshes the installed skills; your edits are kept
php artisan agentio:setup-youtrack    # refreshes the automation guide in the knowledge base
```

Commit the updated `.claude/skills/agentio-*` and `.agentio.json`. While the version is `0.x`, `^0.7` takes only the `0.7.*` releases: a `0.8.0` may change behaviour (see the [changelog](CHANGELOG.md)), so move to it with `composer require alexxosipov/agentio:^0.8 --dev` when it is out; from an older minor version — `composer require alexxosipov/agentio:^0.7 --dev`. To try the latest unreleased code, require `dev-main`.

Up to `v0.4.0` the package was called `obrazmisli/agentio`. Composer no longer finds it under that name, so a project that requires it switches with `composer remove obrazmisli/agentio --dev && composer require alexxosipov/agentio:^0.7 --dev` (the repository entry stays the same), then commits `composer.json` and `composer.lock` to the development branch.

A project that has a copy of the package (a `path` repository such as `packages/agentio`) switches by replacing that repository with the `vcs` one above, running `composer require alexxosipov/agentio:^0.7 --dev` and deleting the copy. Commit the new `composer.json` and `composer.lock` to the development branch (`dev`) too, not only to the branch you work on: epic worktrees start from `dev` and run `composer install` from its lock file, which would still look for `packages/agentio`. `agentio:run` refuses to start while the development branch installs a package from a path it does not have, and `agentio:worktree` explains it for an epic branch made before the fix (merge `dev` into it).

For local development of the package, use a path repository in the host project's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../agentio", "options": { "symlink": true } }
]
```

```bash
composer require alexxosipov/agentio:@dev --dev
```

## Branches

| Branch | Role | Who changes it |
|---|---|---|
| `main` (`AGENTIO_PRODUCTION_BRANCH`) | Production | Humans: release `dev` into it |
| `dev` (`AGENTIO_BASE_BRANCH`) | The develop server; epic branches start from it | Humans and `agentio:accept`: merge accepted epics into it |
| `<EPIC-ID>`, e.g. `TP-12` | One epic: its tasks are committed here (`TP-14: …`), in the worktree `<worktrees>/TP-12` | The agents |

Agents never push to, switch to or merge into `dev` and `main` (the session permissions and the `agentio-guard` hook refuse it); they may push and delete only branches named after issues. Epics started by an earlier agentio version keep their `epic/<ID>-<slug>` branch until they are accepted.

## What agentio adds to the project

| Where | What |
|---|---|
| `.claude/skills/agentio-*` | The skills of the cycle (commit them) |
| `.agentio.json` | The YouTrack project, base branch, merge policy, knowledge base article ids and the hashes of the installed skills (commit it) |
| `compose.yaml`, `docker/postgres/initdb/01-create-testing-db.sh` | The [local services](#local-services): PostgreSQL with the development and the `testing` database, Redis (commit them; `--skip-services` leaves them out) |
| `phpunit.xml` | The tests run on the PostgreSQL database `testing`: `DB_CONNECTION=pgsql`, `DB_DATABASE=testing`, `DB_URL=` (commit it) |
| `.env.example` | `DB_*` and `REDIS_*` of the local services (commit it) |
| `.env` | `DB_*` and `REDIS_*` of the local services, `YOUTRACK_URL`, `YOUTRACK_TOKEN`, `AGENTIO_WORKTREES_PATH`; with the Telegram bot `AGENTIO_TELEGRAM_BOT_TOKEN`, `AGENTIO_TELEGRAM_CHAT_ID`, `AGENTIO_TRANSCRIPTION_*` (local, git-ignored) |
| `composer.json`, `config/horizon.php` | Only when you set up the Telegram bot and the project has no Horizon: `laravel/horizon` (and `predis/predis` without the redis extension), installed by `agentio:setup-telegram` with `horizon:install` |
| Claude Code (`~/.claude.json`) | The `youtrack` MCP server with your token (local scope: this project only, private) |

Nothing else: no scripts of the cycle, hooks, subagents, `.claude/settings.json` or `.mcp.json` changes, no `CLAUDE.md` block. Everything else is in the package and is reached through artisan:

| Package path | Used by |
|---|---|
| `scripts/agent-loop.sh`, `epic-worktree.sh`, `agent-commit.sh` | `agentio:run`, `agentio:worktree`, `agentio:commit` (the scripts refuse to run on their own) |
| `resources/claude/settings.json`, `planning.json`, `assistant.json` | Permission rules of the agents' epic and planning sessions and of the bot's assistant (passed with `--settings`, with the guard hook) |
| `resources/boost/guidelines/core.md` | The agentio guideline for Laravel Boost: choose `alexxosipov/agentio` in `php artisan boost:install` and Boost adds it to `CLAUDE.md` |
| `bin/agentio-guard` | The PreToolUse hook of the agents' Bash commands |
| `resources/claude/mcp/*.json` | MCP servers of the headless sessions (`youtrack`, `laravel-boost` when the project uses Boost) |
| `resources/docs/AUTONOMOUS_WORKFLOW.md` | The manual (copied into the YouTrack knowledge base by `agentio:setup-youtrack`) |

### Skills

Packaged as Claude Code skills (a `SKILL.md` with `name` and `description` under 500 lines each, details in `references/` one level deep), all prefixed with `agentio-` so they never clash with the project's own skills or with built-in commands such as `/plan` and `/status`:

| Skill | Kind | Purpose |
|---|---|---|
| `agentio-youtrack-workflow` | background (`user-invocable: false`) | Process rules: hierarchy, Stage, readiness, claims, `[AGENT:*]` comments, MCP tools, queries, knowledge base map |
| `agentio-project-manager`, `agentio-system-analyst`, `agentio-laravel-architect` | roles | Requirements and decomposition; system analysis per module and feature; architecture decisions in module and feature articles, ADR, references on queues, idempotency, payments, external APIs and operations |
| `/agentio-plan <ID or text>` | command (`disable-model-invocation`) | Full planning of an idea |
| `/agentio-work-epic <EPIC>` | command (`disable-model-invocation`) | Epic orchestrator: starts subagents with the next two skills |
| `agentio-develop-task`, `agentio-review-story` | used by subagents | One TASK: code, tests, commit; one STORY: review against acceptance criteria |
| `/agentio-dispatch`, `/agentio-status` | read-only commands | What can start now; a summary of the project |
| `agentio-saloon` | role | Integrations with external HTTP APIs: a maintained SDK when the service has one, otherwise [Saloon](https://docs.saloon.dev) v4 (connector, requests, auth, errors and retries, DTOs, pagination, rate limits, OAuth2, tests with `MockClient`); every integration lives in `App\Integrations\<Service>` |
| `agentio-platform-skill` | role | Creating the project's own skill for an external platform (Telegram Mini App, Telegram bot, VK Mini App, PWA, a payment provider…) as the first task of an epic that brings the product there |
| `agentio-telegram-assistant` | background (`user-invocable: false`) | The project manager behind the Telegram bot: reads a message of the developer and decides (answer to questions, comment, new idea), read-only |

The skills work together with [Laravel Boost](https://github.com/laravel/boost): the project's conventions (`CLAUDE.md`/`AGENTS.md`, `.ai/guidelines`, `.ai/rules`, the existing code) come before the defaults of the agentio references (the domain namespaces are the default only for a project without a layout of its own), the Boost skills of the touched areas are loaded before the work, and what Boost leaves to "ask the user" becomes a question in `[AGENT:BLOCKED]`. The one dependency the agents may add by themselves is Saloon (`saloonphp/*`): an integration with an external API wraps the service's maintained SDK when there is one and is written on Saloon when there is none, always in `App\Integrations\<Service>` (tests in `tests/Feature/Integrations/<Service>`).

## YouTrack through MCP

The agents read and write YouTrack only with the tools of the YouTrack MCP server (`mcp__youtrack__*`: issues, comments, fields, tags, links, articles); they never call the REST API and never see the token. What needs a deterministic computation over the issue graph is done by `php artisan agentio:yt`, which uses the same MCP server:

```bash
php artisan agentio:yt ideas                      # ideas waiting for planning
php artisan agentio:yt ready-epics                # epics the loop may start, with their first wave of tasks
php artisan agentio:yt tree TP-2                  # epic -> stories -> tasks with readiness and unmet dependencies
php artisan agentio:yt ready-tasks TP-2
php artisan agentio:yt validate TP-1              # structure checks of an epic or of the epics of an idea (exit 2)
php artisan agentio:yt claim TP-14 --as=TP-14     # [AGENT:START] + agent-claimed + In Progress, verified (LOST: exit 3)
php artisan agentio:yt release TP-14 --state=Done # Stage + the tag removed
php artisan agentio:yt blocked | claimed-epics | state <ID> | kb-tree [<ARTICLE>] [--depth=N]   # all with --json
```

The REST API is used only where the MCP server has no tool: `agentio:setup-youtrack` (custom fields, bundles, tags, saved searches), creating a missing project in `agentio:install`, and the read-only dashboard and `agentio:status` (they need the whole link graph and the comment feed of the project, which the MCP tools do not return).

## Setting up a project

```bash
php artisan agentio:install                     # asks for everything, installs the skills
php artisan agentio:setup-youtrack --dry-run    # plan the YouTrack setup (changes nothing)
php artisan agentio:setup-youtrack              # apply it (safe to run again at any time)
```

### `agentio:install`

In a terminal the command asks:

1. **YouTrack URL** — default `YOUTRACK_URL` (environment or `.env`); leave it empty to install the skills without YouTrack.
2. **Token** — hidden input; when a token is already set it offers to keep it. Create a permanent token in YouTrack → Profile → Account Security → Tokens. The access is checked through the MCP server (`get_current_user`); on an error it explains what failed and asks again.
3. **The youtrack MCP server** — `claude mcp get youtrack` tells whether Claude Code already has one for the project; if not, it is added: `claude mcp add --transport http --scope local youtrack <url>/mcp --header "Authorization: Bearer <token>"`. A local-scope server is stored by Claude Code in `~/.claude.json` under the project path: private, never committed. `--mcp-scope=user` adds it for all your projects instead (handy for working interactively in the epic worktrees, which have other paths). When you enter a new token, the server is replaced.
4. **Project short name** — default `.agentio.json`, then `AGENTIO_PROJECT`. When the project does not exist (or the token cannot see it), it offers to create it (with the token owner as the leader; the token needs the permission to create projects).
5. **Production branch** (default: `.agentio.json`, then `main`, `master` or the current branch), **development branch** (default: `.agentio.json`, then `dev`) and **merge policy** (`local-branch`, `pull-request`, `auto-merge`). A missing production branch is created from the current commit, a missing development branch from the production branch — locally, without switching the checkout; branches the remote lacks are reported so that you push them.
6. **Directory of the epic worktrees** — every epic gets `<directory>/<EPIC-ID>`; it must be outside the project and is written to `.env` as `AGENTIO_WORKTREES_PATH`. There is no silent default: the loop refuses to start without it.
7. Whether to configure the YouTrack project now (`agentio:setup-youtrack`).

Options given on the command line are not asked again. Without a terminal or with `--no-interaction` nothing is asked: the values come from the options and the environment, `--worktrees` (or `AGENTIO_WORKTREES_PATH`) is required, a rejected token fails the command and a missing project is created only with `--create-project`:

```bash
YOUTRACK_TOKEN=perm-... php artisan agentio:install --no-interaction \
    --youtrack-url=https://example.youtrack.cloud --project=ABC --create-project --project-name="Our product" \
    --worktrees=../abc-worktrees --setup-youtrack
```

| Option | Meaning |
|---|---|
| `--youtrack-url=URL` | YouTrack URL. Default: `YOUTRACK_URL` |
| `--token=TOKEN` | Permanent token. Default: `YOUTRACK_TOKEN` (prefer the variable or the prompt: options stay in the shell history) |
| `--project=KEY` | Project short name. Default: `.agentio.json`, then `AGENTIO_PROJECT` |
| `--create-project`, `--project-name=NAME` | Create the project when it does not exist (asked when interactive); its name defaults to the short name |
| `--base-branch=BRANCH` | Development branch (default `dev`): what the develop server runs; epic branches start from it and are merged into it |
| `--production-branch=BRANCH` | Production branch (default `main`): humans release the development branch into it |
| `--merge-policy=POLICY` | `local-branch` (the branch stays local), `pull-request` (push + PR via `gh`) or `auto-merge` |
| `--worktrees=DIR` | Directory of the epic worktrees, outside the project |
| `--mcp-scope=local\|user` | Scope of the `youtrack` MCP server added to Claude Code (default `local`) |
| `--setup-youtrack` | Also configure the YouTrack project (asked when interactive) |
| `--skip-services` | Do not add the local services nor point `.env`, `.env.example` and `phpunit.xml` at them |
| `--force` | Overwrite installed skills that were edited locally |
| `--dry-run` | Only print the plan |

The token is never printed. The command is idempotent and safe to rerun after updating the package: a skill file with the expected content is left alone, one that still has the content of the previous install is updated, one you edited is skipped with a warning (`--force` overwrites it), and a skill file of an earlier version that the package no longer ships is removed unless you edited it.

### Local services

`agentio:install` sets the project up to run and test on PostgreSQL and Redis in Docker, so that a fresh Laravel application is ready for the cycle (and Redis is there for the queue of the Telegram bot):

- `compose.yaml` — `postgres:18-alpine` (port `DB_PORT`, default 5432) and `redis:7-alpine` (port `REDIS_PORT`, default 6379), each with a volume and a health check; PostgreSQL takes the database, the user and the password from `.env` (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
- `docker/postgres/initdb/01-create-testing-db.sh` — creates the `testing` database of the tests next to the development one when the PostgreSQL volume is created (after `docker compose down -v`, or on a volume made before the script: `docker compose exec postgres createdb -U <user> testing`).
- `.env` and `.env.example` — a project on SQLite (the default of a new application) is switched to PostgreSQL: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=laravel`, `DB_USERNAME=laravel`, `DB_PASSWORD=password` (the commented-out `# DB_HOST=…` lines of a new project are replaced in place); `REDIS_HOST` and `REDIS_PORT` are added when missing. A project already on PostgreSQL keeps its values and gets only the missing keys.
- `phpunit.xml` (or `phpunit.xml.dist`) — `DB_CONNECTION=pgsql`, `DB_DATABASE=testing` and an empty `DB_URL`: the tests run on the `testing` database, never on the development one.

Then `docker compose up -d` and `php artisan migrate`. A project on another database (MySQL, MariaDB, SQL Server) is left alone with a warning, an existing `compose.yaml` of its own is kept (`--force` replaces it), and `--skip-services` skips all of it.

The agents run tests in parallel — several epics at once and the tasks of a wave together — so in their sessions every test process gets its own database: the loop sets `AGENTIO_ISOLATED_TESTS=1`, and the service provider of agentio makes each test process a parallel-testing process of Laravel with a token of its own (`agentio_<slot>`, a slot of the machine locked while the process runs). Laravel creates and migrates `testing_test_agentio_<slot>` for it and keeps its cache and compiled views apart; your own `composer test` keeps using `testing`. Nothing changes for tests on an in-memory SQLite database.

### `agentio:setup-youtrack`

Configures the YouTrack project for the cycle and checks its board. It can run any number of times: everything is looked up first and **nothing is created twice**; nothing is moved, only YouTrack's own Stage values below are deleted, and only the fields and values below are renamed. It exits with code 3 when everything else is set up but the board needs a human (then `agentio:install` still installs the skills and fails at the end).

- **Fields.** The cycle uses the fields YouTrack gives new projects — **Stage** (the status of an issue) and **Type** («Тип») — and never creates a second field of the same meaning: a field is found by its name or by its localized name, and the missing values are added to its bundle (Stage: Backlog, Analysis, Ready, In Progress, Review, Blocked, Done; Type: Idea, Epic, Story, Task). The values YouTrack gives Stage that the cycle does not use — Develop, Test, Staging — are deleted from the project's own bundle, so nobody moves an issue into a status the cycle never leaves; a value that issues of the project are in is kept with a warning (move them, then rerun). New issues default to Stage `Backlog` and Type `Task` when the field has no default among these values (YouTrack's `Submitted` would hide an idea from the loop). A bundle shared with other projects, or the default bundle YouTrack gives new projects, is never changed: a project without issues is switched to its own `KEY Stages` / `KEY Types`, a project with issues gets a warning.
- **English names.** The agents read the values through the YouTrack MCP server, which answers with the localized name of a value: a YouTrack in Russian shows `Backlog` as «Очередь», `Done` as «Готово», and the cycle would not recognize them. So in the project's own bundles the cycle's values lose their localized name (they are shown as `Backlog`, `Review`, `Done`, …), a value named in Russian («Очередь», «В работе», «Задача», …) is renamed to the cycle's name (its issues keep it), and a Stage or Type field named in Russian («Этап», «Тип») is renamed to `Stage` / `Type` — a global field, so every project that uses it sees the new name.
- **Board.** The project must have an agile board with **columns by Stage** and **swimlanes by Type**: the setup checks every board of the project and reports an error with what is wrong (columns by another field, no swimlanes, swimlanes by another field) when none fits; a fitting board without a column for some stage gets a warning. The board is the team's own view, so the setup never creates or changes it: in YouTrack, open the board settings → Columns and rows, set the columns to Stage (a column per value) and the swimlanes to Type, then rerun.
- **No State field.** Status is Stage only. A `State` field («Состояние») of the project is reported and left as is. In a project set up by an earlier agentio version the status lives in State: copy it to Stage (a bulk update by `State: <value>` in YouTrack).
- The `idea` and `agent-claimed` tags and the `KEY: …` saved searches.
- The knowledge base tree (through the MCP server): product overview, system analysis (the root with the module map and «Общие требования»), architecture (overview, data model, ADR, ADR-001), development process, the automation guide and the glossary. New articles are short templates; existing articles with the same title are kept as they are — except «Руководство по автоматизации», which is kept equal to the manual of the installed agentio version.

The article ids are written to `.agentio.json` and into the installed skills.

| Option | Meaning |
|---|---|
| `--project=KEY` | Project short name. Default: `AGENTIO_PROJECT`, then `.agentio.json` |
| `--dry-run` | Only print the plan |

### Knowledge base

The system analysis is kept as a tree of product modules and their features, not as one article per analysis layer:

```
Системная аналитика        root: the method in short and the module map (module → article → features)
├── Общие требования       roles and permissions, cross-cutting non-functional requirements, external systems, global constraints
├── Пользователи           a module: purpose, scope, roles, features, entity index, dependencies, module architecture
│   ├── Регистрация        a feature: goal, scenarios, FR/BR, data model, interfaces, system behaviour (retries, failures,
│   │                      long operations, money), NFR, risks — and its architecture: decisions AD-n with their reasons
│   └── Профиль и аватар
└── …
```

`agentio:setup-youtrack` creates only the root and «Общие требования»; the `agentio-system-analyst` skill adds modules and features as the product grows. `php artisan agentio:yt kb-tree [<ARTICLE>] [--depth=N] [--json]` prints the tree with article ids; agents create articles under their parent with the `create_article` tool of the MCP server (`parentArticle`). Articles of the earlier per-layer structure («1. Бизнес-контекст и цели» … «7. Ограничения, допущения и риски») are kept as a read-only archive; the analyst moves their content into feature articles.

The architecture lives in the same tree, next to the requirements it implements: the `agentio-laravel-architect` skill writes the decisions of a feature (with their reasons and rejected options, numbered `AD-n`) into the «Архитектура» section of the feature article and the decisions of a module into «Архитектура модуля» of the module article. ADRs are kept for project-wide decisions; «Архитектура: обзор» maps the modules to the code and the infrastructure, «Модель данных» indexes the tables by their feature articles. The code is laid out by domain namespaces (`App\<Domain>\<Kind>\<Class>`, a domain per module) instead of flat `App\Actions`, `App\Models` or `App\Http\Controllers`; migrations, factories, seeders, config and routes stay where Laravel puts them, and every test mirrors the namespace of the class it tests. No article per epic is created any more: the implementation order of an epic goes into its description, and the «Эпик <ID>: …» articles of earlier versions are a read-only archive whose decisions the architect moves into feature and module articles.

The analyst and the architect load the `laravel-best-practices` skill of Laravel Boost (`agentio:install` reports when the project has none) and follow its rule files: the analyst writes requirements the stack can meet and goes through the behaviour of the system (retries, concurrency, long operations, failures of external systems, money), the architect designs by it plus the references of the skill on queues, idempotency, payments (idempotency keys, webhooks, reconciliation), external APIs (timeouts, retries, circuit breaking) and operations.

### Questions to the human

When only the human can decide (a business rule, money, a contract, legal requirements), the agent first finishes and records everything that does not depend on the answer, then asks **all** its questions in **one** `[AGENT:BLOCKED]` comment of the issue it works on (the idea while planning): numbered questions В1, В2, … with options, their consequences and the agent's recommendation, and the Stage to return the issue to. The issue goes to `Blocked`. The questions are always in Russian. Answer **with a comment in that issue** in YouTrack (`В1: б; В2: а`, or `Принимаю рекомендации`) — or reply to the question in the [Telegram bot](#telegram-bot) — then set Stage back as the comment says (an idea to `Backlog`, other issues to `Ready`); returning the Stage without a comment accepts all recommendations. The next pass of the loop resumes the work, moves the answers into the knowledge base articles (so they are not lost in comments) and records an `[AGENT:DECISION]`. Blocked issues and their questions are listed on the dashboard, by `php artisan agentio:yt blocked` and by the saved search «KEY: заблокированные».

## Running the loop

```bash
php artisan agentio:run --dry-run       # what would be started: ideas, ready epics, their trees, blocked issues
php artisan agentio:run --once          # one pass, waiting for the started epic sessions
php artisan agentio:run                 # run forever (a pass every AGENTIO_INTERVAL seconds)
php artisan agentio:run --stop          # ask the running loop to exit after its current step
php artisan agentio:run --kill          # stop running agent sessions (claims are kept and resumed later)
php artisan agentio:run --no-telegram   # without the Telegram bot
```

`agentio:run` runs the loop of the package (`scripts/agent-loop.sh`) with the settings of `config/agentio.php` and `.agentio.json` in its environment. The variables Laravel loaded from the project's `.env` (`APP_ENV`, `APP_KEY`, `DB_*`, …) are removed from that environment: inherited by the agents, they would override the `.env` of every epic worktree and the `<env>` values of `phpunit.xml`. Options passed through: `--once`, `--dry-run`, `--kill`, `--epic=KEY-N`, `--no-plan`, `--no-wait`, `--interval=SEC`, `--max-parallel=N`, `--max-parallel-tasks=N`. A leftover stop flag is reported; `--fresh` removes it before starting. SIGINT and SIGTERM are forwarded to the loop (it finishes its current step; agent sessions keep running), and the exit code is the loop's. The loop keeps `loop.pid` in the logs directory while it runs and refuses to start twice in one checkout.

Each pass starts the epics that can go on (claimed by this machine and interrupted, or ready) — preparing the worktree with `agentio:worktree` and starting `/agentio-work-epic <EPIC>` in it — and plans the ideas one at a time with `/agentio-plan <IDEA>` (an interrupted planning first). What this machine left unfinished is computed by `agentio:yt resumable`. The loop does not wait for a planning session, and when any session finishes it starts the next pass right away instead of waiting for the interval. Before starting an epic it checks that the skills are committed to the development branch.

A session that ends without finishing its work (an epic not in `Review`, `Blocked` or `Done`, an idea not planned) is counted in `<NAME>.restarts`; new commits on the epic branch reset the count. After `MAX_RESTARTS` (3) such sessions in a row the issue gets an `[AGENT:BLOCKED]` comment and goes to `Blocked`. A session counts as alive only while its pid is still a Claude Code process, so a pid reused after a reboot does not hold a slot, and `loop.lock` (`flock`) keeps a second loop from starting in the same checkout.

Headless sessions run `claude -p … --permission-mode dontAsk` with everything from the package: `--settings` (the package's session settings merged with the allow/deny rules of the project's own `.claude/settings.json`, if any — Claude Code ignores the allow rules of a directory whose workspace trust was never accepted, and every new epic worktree is such a directory), and `--strict-mcp-config --mcp-config` with the `youtrack` server (and `laravel-boost` when the project uses Boost), whose `${YOUTRACK_URL}` and `${YOUTRACK_TOKEN}` come from the loop's environment.

### Commands of the agents

| Command | Purpose |
|---|---|
| `php artisan agentio:yt <action>` | YouTrack helper over the MCP server (see above) |
| `php artisan agentio:commit <TASK> "<message>" <files…>` | Commit only the given files with the `<TASK>: ` prefix, under a repository lock |
| `php artisan agentio:worktree <EPIC> [--remove]` | Create and prepare the worktree of an epic (used by the loop) |
| `php artisan agentio:log <ID>` | Print a session log in a readable form (`--lines`, `--follow`) |

The agents run tests the project's own way: `composer test` (the full quality gate) at the end of every task, and `vendor/bin/pest <files> [--filter=…]` for single tests while they work. There is no other way to run them.

`agentio:worktree` gives each worktree its own `.env` (made from `.env.example`: own `APP_KEY`, SQLite database, cache/queue/session prefixes, `APP_URL` port), runs `composer install`, migrations and — when there is a `bun.lock` or `package-lock.json` and a `build` script — the frontend build. The tests of a worktree still run on the PostgreSQL server of `.env.example` (the `<env>` of `phpunit.xml` come first), each test process on its own database (see [Local services](#local-services)). Add project-specific steps (seeders, services) with `AGENTIO_WORKTREE_SETUP`: a shell command run last in the new worktree with the epic id as `$1`.

### `agentio:status`

```bash
php artisan agentio:status              # loop, live sessions with their last event, YouTrack summary
php artisan agentio:status --json
php artisan agentio:status --local      # without YouTrack
```

The YouTrack part shows counts by Type × Stage, ideas waiting for planning, ready epics with their first wave of tasks, work in progress, blocked issues with the reason from their last `[AGENT:BLOCKED]` comment, and epics awaiting a human.

## Telegram bot

An optional bot of your own, a project manager in a chat. It never holds up the cycle: the agents neither wait for it nor write to it, YouTrack stays the source of truth, and a failure of Telegram, Redis or the assistant never stops the loop.

- **It sends** the questions of the agents (every new `[AGENT:BLOCKED]`, in Russian) and short reports: an idea was planned (its epics), an epic is ready for review (what was done) or was merged, a session gave up.
- **It understands** text and voice messages. Reply to a question (`В1: б`, «принимаю рекомендации», or in your own words, by voice too): the answer becomes a comment of the issue («Ответ разработчика (Telegram)…») and, when it answers every question, the issue returns to the Stage its `[AGENT:BLOCKED]` names, so the loop resumes the work. Describe a new feature and it becomes an idea (`[IDEA]`, tag `idea`) for the loop to plan; ask anything about the project (what is in progress, what is blocked, what was done) and it answers from YouTrack, the loop and the code.
- **How**: an assistant — a read-only headless Claude Code session with the `agentio-telegram-assistant` skill — reads the message with its context (the message it replies to and the issue behind it, the recent conversation) and answers with a decision; agentio makes the YouTrack changes itself, through the MCP server, and tells you what it did.

Set it up:

1. Create a bot with [@BotFather](https://t.me/BotFather) (`/newbot`) and copy its token.
2. `php artisan agentio:setup-telegram <token>` (or answer yes in `agentio:install`). It checks the token (`getMe`), writes it to `.env` as `AGENTIO_TELEGRAM_BOT_TOKEN`, asks how to turn voice messages into text (`openai` — any OpenAI-compatible `/audio/transcriptions` API: OpenAI, Groq, a local whisper server; `whisper` — whisper.cpp on this machine with a ggml model and ffmpeg; `none`), makes sure Laravel Horizon is installed and works the queue (installs it when missing), restarts whatever still uses the old settings, and pairs your chat: open the `t.me/<bot>?start=<code>` link it prints and press Start (the chat id goes to `.env` as `AGENTIO_TELEGRAM_CHAT_ID`; only that chat is answered).
3. `php artisan agentio:run` starts the bot next to the loop, each in a process of its own: the listener (`agentio:telegram listen`: long polling with `getUpdates`, the questions of the agents every `AGENTIO_TELEGRAM_WATCH_INTERVAL` seconds; log `telegram.log`) and, when the project's Horizon is not running, `php artisan horizon` (log `horizon.log`). A process that ends is started again with a growing pause. When a key of `.env` they use changes — a new token through `agentio:setup-telegram` or `agentio:install`, or by hand — they are restarted with it (the project's own Horizon gets `horizon:terminate`).

Every message, in and out, goes through the `default` queue of the `redis` connection (`AGENTIO_TELEGRAM_QUEUE`, `AGENTIO_TELEGRAM_QUEUE_CONNECTION`), worked by Horizon; the assistant runs detached from the queue worker (a Claude Code session takes longer than a job may) and answers the messages one at a time, in order. The bot keeps its state (update offset, which message is about which issue, the recent conversation) in `<AGENTIO_LOGS_PATH>/telegram`, so run `agentio:run` and Horizon on the same machine.

```bash
php artisan agentio:telegram status          # token, chat, voice messages, queue, Horizon
php artisan agentio:telegram send "Привет"   # a test message through the queue
```

The Telegram Bot API and the transcription APIs are called with the HTTP client of Laravel: the package depends on no SDK and no HTTP client library. An application adds its own transcription driver with `app(TranscriptionManager::class)->extend('name', fn ($app) => new MyTranscriber(...))` (`Obrazmisli\Agentio\Telegram\Transcription\Transcriber`).

## Dashboard

The process dashboard is served at `/agentio` (`AGENTIO_UI_PATH`, `AGENTIO_UI_DOMAIN`, disabled with `AGENTIO_UI_ENABLED=false`). It is a Blade page with the package's own script and stylesheet (served by the package, no CDN, no frontend build in the host project) that polls JSON endpoints every `AGENTIO_UI_POLL` seconds and pauses while the browser tab is hidden. Publish the view with `php artisan vendor:publish --tag=agentio-views` to customise it.

Screens:

- **Overview** (`#/`): the loop status in the header (running / stopping / stopped, live sessions, merge policy, YouTrack connection); *who works now* — every live Claude Code session with its issue, pipeline stage, the tasks being worked on, its latest tool calls, subagents and messages, plus the issues claimed by agents and the last finished sessions with their cost and duration; the *pipeline* of every idea and epic through the stages Idea → Requirements and analysis → Architecture → Decomposition → Development → Story review → Acceptance → Done, with task progress and the reason of a blocked item (its last `[AGENT:BLOCKED]`); the `[AGENT:*]` *event feed*; the tail of `loop.log`.
- **Kanban** (`#/board`): every issue of the project in a column per Stage, filtered by epic, type or text, with claims and unmet dependencies.
- **Loop log** (`#/log`): the last lines of `loop.log`.
- **Epic** (`#/epic/<ID>`): the stage and progress of an epic (or an idea), its STORY → TASK tree with readiness and dependencies, the progress of each story, the tasks ready to be taken, the tasks waiting for dependencies and the `[AGENT:*]` feed of the whole tree. While the epic has a branch, the *branch and acceptance* panel shows its commits and changed files relative to the base branch (click a file for its diff), the orchestrator's final `[AGENT:DONE]`, the review verdict of every story, the epic worktree with its `APP_URL`, and the checks a merge has to pass.

### Accepting an epic from the dashboard

When an epic is in `Review`, its page offers the human's two decisions (turn them off with `AGENTIO_UI_ACTIONS=false`):

- **Accept** (also `php artisan agentio:accept <ID>`; the loop runs it for `auto-merge` without a remote): `git merge --no-ff` of the epic branch into the development branch of the checkout the application runs from (nothing is pushed), then, as ticked, `git worktree remove` of the epic worktree, `git branch -d` of the branch, and the stories in `Review` plus the epic moved to `Done` with a comment on the epic (the epic stays in `Review` if a story has not passed review). The merge runs only when the epic is in `Review`, no agent session works on it, the epic worktree has nothing uncommitted, and the main checkout is on the base branch with no uncommitted changes to tracked files; a merge that conflicts is aborted and the conflicting files are listed. When the base branch already contains the epic (a pull request was merged), accepting only cleans up and closes the issues.
- **Send back for rework**: a `Ready` TASK `[TASK] Review: …` with the remark in the chosen story, the remark as a comment of the story, the story and the epic back to `Ready`. The loop resumes the epic in the same worktree and branch.

Issues are changed through the YouTrack MCP server, like the agents do; one action runs at a time, and the actions are POST requests protected by the session's CSRF token (so `ui.middleware` must start a session, as `web` does).

Every issue id links to YouTrack. Without YouTrack (not configured or not reachable) the page keeps showing the local sessions and the loop log and says what is missing.

| Endpoint (GET) | Returns |
|---|---|
| `/agentio` | The page |
| `/agentio/assets/app.css`, `/agentio/assets/app.js` | The stylesheet and the script (cached forever with their `?v=` content hash) |
| `/agentio/api/status` | Project, loop status and pid, stop flag, live sessions count, merge policy, logs directory, YouTrack connection |
| `/agentio/api/sessions` | Live sessions with their issue, stage, current tasks and latest events; recently finished sessions; claimed issues |
| `/agentio/api/pipeline` | Ideas and epics with their pipeline stage, progress and blocking reason |
| `/agentio/api/board` | Kanban columns by Stage and the list of epics |
| `/agentio/api/events` | The latest `[AGENT:*]` comments of the project |
| `/agentio/api/loop-log` | The last lines of `loop.log` |
| `/agentio/api/epics/{id}` | One epic or idea in detail (404 when YouTrack does not know the issue) |
| `/agentio/api/epics/{id}/review` | The epic branch (commits, changed files, merged or not, worktree), the merge checks, the final `[AGENT:DONE]` and the story verdicts |
| `/agentio/api/epics/{id}/diff?file=PATH` | The diff of one file the epic branch changes (404 for any other file) |

| Endpoint (POST) | Does |
|---|---|
| `/agentio/api/epics/{id}/accept` | Accept the epic: `removeWorktree` (default true), `deleteBranch` (default false), `close` (default true); 409 with `details` when a check fails or the merge conflicts |
| `/agentio/api/epics/{id}/rework` | Send the epic back: `story` (a story of the epic) and `remark` |

The data comes from YouTrack (cached for `AGENTIO_UI_CACHE` seconds, so polling browsers do not hit YouTrack on every request; a failure is cached for at least 30 seconds, so an unreachable YouTrack does not hold up every poll) and from the files the loop writes to `AGENTIO_LOGS_PATH` (pid files, `loop.log`, stream-json session logs) plus the stop flag. The data of YouTrack is read through its REST API: the dashboard needs the link graph and the comment feed of the whole project, which the MCP tools do not return. The YouTrack token never reaches the browser: should a session log or an error message contain it, it is replaced with `[redacted]`.

### Access

Every route of the dashboard, the assets included, goes through the `ui.middleware` (`web` by default) and the `viewAgentio` gate; the actions (accept, send back) also need the `manageAgentio` gate and a session (its CSRF token is checked). In the `local` environment everyone passes both gates; elsewhere only an authenticated user whose email is listed in `AGENTIO_ALLOWED_EMAILS` (comma separated), everybody else gets 403. To change the rules, define your own `viewAgentio` / `manageAgentio` gates in a service provider (e.g. everybody of the team may watch, only leads may merge), or replace both checks with `Agentio::auth()` (`Agentio::auth(null)` restores the gates):

```php
use Illuminate\Http\Request;
use Obrazmisli\Agentio\Agentio;

if (class_exists(Agentio::class)) {
    Agentio::auth(fn (Request $request): bool => $request->user()?->isAdmin() === true);
}
```

## Configuration

Everything can be set from `.env`; publish the config with `php artisan vendor:publish --tag=agentio-config` if you want to change it in code:

| Variable | Config key | Default | Meaning |
|---|---|---|---|
| `YOUTRACK_URL`, `YOUTRACK_TOKEN` | `youtrack.url`, `youtrack.token` | — | YouTrack instance and permanent token (read only from the environment; written to `.env` by `agentio:install`) |
| `AGENTIO_PROJECT` | `youtrack.project` | the project in `.agentio.json`, then `TP` | YouTrack project short name |
| `AGENTIO_YOUTRACK_TIMEOUT` | `youtrack.timeout` | 30 | Seconds per YouTrack request (MCP and REST) |
| `AGENTIO_YOUTRACK_RETRIES` | `youtrack.retries` | 2 | Retries of a request after a connection error, a 5xx or a 429 |
| `AGENTIO_BASE_BRANCH` | `base_branch` | the branch in `.agentio.json`, then `dev` | Development branch: epic branches start from it and are merged into it |
| `AGENTIO_PRODUCTION_BRANCH` | `production_branch` | the branch in `.agentio.json`, then `main` | Production branch: agents never touch it; humans release the development branch into it |
| `AGENTIO_MERGE_POLICY` | `merge_policy` | the policy in `.agentio.json`, then `local-branch` | `local-branch`, `pull-request` or `auto-merge` |
| `AGENTIO_WORKTREES_PATH` | `worktrees_path` | — (asked by `agentio:install`) | Where epic worktrees are created; the loop refuses to start without it |
| `AGENTIO_WORKTREE_SETUP` | `worktree_setup` | — | Shell command run last in every new epic worktree with the epic id as `$1` |
| `AGENTIO_MAX_PARALLEL`, `AGENTIO_MAX_PARALLEL_TASKS` | `max_parallel`, `max_parallel_tasks` | 2, 2 | Epics at the same time; task subagents per epic |
| `AGENTIO_INTERVAL` | `interval` | 300 | Seconds between passes of the loop |
| `AGENTIO_CLAUDE_BIN`, `AGENTIO_CLAUDE_MODEL` | `claude_binary`, `claude_model` | `claude`, — | Claude Code executable; model of the headless sessions |
| `AGENTIO_LOGS_PATH` | `logs_path` | `storage/logs/agents` | Where the loop writes `loop.log`, `loop.pid`, `<ID>.pid`/`<ID>.log` (epic sessions), `plan-<ID>.pid`/`plan-<ID>.log` (planning sessions) and the `stop` flag; the dashboard, `agentio:status` and `agentio:log` read it |
| `AGENTIO_TIMEZONE` | `timezone` | the machine's time zone | Time zone of the local timestamps the loop writes (`loop.log`, session headers); detected from `$TZ`, `/etc/timezone` or `/etc/localtime` when empty |
| `AGENTIO_UI_ENABLED` | `ui.enabled` | `true` | Register the dashboard routes |
| `AGENTIO_UI_PATH`, `AGENTIO_UI_DOMAIN` | `ui.path`, `ui.domain` | `agentio`, — | Where the dashboard is mounted |
| — | `ui.middleware` | `['web']` | Middleware of the dashboard routes (the `viewAgentio` check is always added) |
| `AGENTIO_UI_POLL` | `ui.poll` | 5 | Seconds between the page's requests |
| `AGENTIO_UI_CACHE` | `ui.cache` | 15 | Seconds YouTrack answers are cached for the dashboard (0 disables the cache; keep it above `AGENTIO_UI_POLL`, or every poll goes to YouTrack) |
| `AGENTIO_UI_ACTIONS` | `ui.actions` | true | Whether the dashboard may accept an epic in Review (merge its branch) and send it back for rework |
| `AGENTIO_ALLOWED_EMAILS` | `ui.allowed_emails` | — | Emails allowed to open the dashboard outside the `local` environment |
| `AGENTIO_TELEGRAM_BOT_TOKEN`, `AGENTIO_TELEGRAM_CHAT_ID` | `telegram.token`, `telegram.chat_id` | — | The developer's bot and the paired chat (written by `agentio:setup-telegram`) |
| `AGENTIO_TELEGRAM_QUEUE_CONNECTION`, `AGENTIO_TELEGRAM_QUEUE` | `telegram.queue_connection`, `telegram.queue` | `redis`, `default` | Where the bot's jobs go (worked by Horizon) |
| `AGENTIO_TELEGRAM_WATCH_INTERVAL` | `telegram.watch_interval` | 60 | Seconds between two looks for new questions of the agents |
| `AGENTIO_TELEGRAM_ASSISTANT_TIMEOUT` | `telegram.assistant_timeout` | 600 | Seconds the assistant may take for a message |
| `AGENTIO_TELEGRAM_API_URL` | `telegram.api_url` | `https://api.telegram.org` | The Bot API server (a local Bot API server, a proxy) |
| `AGENTIO_TRANSCRIPTION_DRIVER` | `telegram.transcription.driver` | — (`none`) | `openai`, `whisper` or `none` |
| `AGENTIO_TRANSCRIPTION_URL`, `_KEY`, `_MODEL`, `_LANGUAGE` | `telegram.transcription.*` | `https://api.openai.com/v1`, —, `whisper-1`, `ru` | The OpenAI-compatible API |
| `AGENTIO_WHISPER_BIN`, `AGENTIO_WHISPER_MODEL`, `AGENTIO_FFMPEG_BIN` | `telegram.transcription.whisper_binary`, `…whisper_model`, `…ffmpeg_binary` | `whisper-cli`, —, `ffmpeg` | whisper.cpp |

The full manual for the people running the cycle is `resources/docs/AUTONOMOUS_WORKFLOW.md` of the package; `agentio:setup-youtrack` keeps a copy of it in the YouTrack knowledge base («Руководство по автоматизации»).

## Security

- The agents work with YouTrack only through the MCP server and are not given the token: it lives in `.env` (git-ignored; `agentio:install` checks that) and in Claude Code's own MCP configuration (`~/.claude.json`), is passed to the loop in its environment and to the MCP config of the headless sessions by reference (`${YOUTRACK_TOKEN}`), and is never printed or written into committed files, logs or comments. The worktree `.env` (made from `.env.example`) has no token. The agents run as your OS user, though, with the token in their environment: the rules below refuse the usual ways to read it, but for a hard boundary run the loop in a sandbox or under a separate user, and use a token that can reach only this project.
- Headless agents run with `--permission-mode dontAsk`: only the commands allowed by the session settings run. Epic sessions get the rules of the package plus the project's own; `git push` to the development and the production branch, force pushes, history rewrites, `composer remove/update` and adding any package other than `saloonphp/*` are denied, `Read(./.env)` is denied, and so are edits of `.agentio.json`, the agentio skills, the files Laravel Boost generates (`CLAUDE.md`, `AGENTS.md`, `boost.json`, `.mcp.json`, `.ai/guidelines`, `.ai/rules`), `vendor/`, `php -i`, `php artisan config:show`, `php artisan test` (the agents run `composer test`), `boost:*` and the `tinker` and `record-rule` tools of Laravel Boost. Web access is limited to search and the official documentation sites of the platforms they write skills for. Planning sessions run in your main checkout and are read-only (`resources/claude/planning.json`): they read the code and write YouTrack, nothing else. The assistant of the Telegram bot is read-only too and cannot write YouTrack (`resources/claude/assistant.json`): agentio makes the changes it decides on.
- The Telegram bot answers only the paired chat; its token and the transcription key live in `.env`, never in a message, a log or an exception (the Bot API URL that contains the token is removed from transport errors).
- `bin/agentio-guard` is the PreToolUse hook of every Bash command of the agents, a second line of defence. It runs from the package of the main checkout without booting the application of the worktree (code the agents edit), takes the protected branches and the extra directories from the loop (never from files of the worktree), and refuses the command when it fails. It splits the command line like a shell (quotes, `$(…)`, pipes, `cd`) and refuses: pushes to protected branches and force pushes, branch moves other than the branches of issues, merges into protected branches, history rewrites, git aliases and `git -c`/`config` writes, `git add -A`/`commit -a`/`--amend`, writes (`rm`, `mv`, `cp`, `sed -i`, `tee`, `>`) outside the project or to the agentio settings, skills and `vendor/`, `find -exec`, `composer require` of anything but `saloonphp/*` (and `composer remove/update`), access to secrets and to the token (`.env`, `YOUTRACK_TOKEN`, `/proc/*/environ`, `php -i`, `config:show agentio`, `printenv`), and the commands only humans and the loop run (`agentio:install`, `agentio:setup-youtrack`, `agentio:worktree`, `agentio:accept`, `agentio:run` other than `--dry-run`, `tinker`), also behind artisan options such as `-n`.
- Agents commit only the files of their task (`agentio:commit`) and never merge into the development branch; humans accept epics (unless you choose `auto-merge`) and release to production.
- Keep the dashboard behind the `viewAgentio` gate (it shows issue data and agent logs) and give `manageAgentio` (merging into the development branch of the checkout the app runs from) only to the people who accept epics.

## Testing

```bash
composer test
```

`composer test` runs PHPStan, Pint, the 100 % type coverage check and the Pest suite (YouTrack, Telegram and the transcription APIs are always faked with `Http::fake()`, processes with `Process::fake()` — the suite refuses a real HTTP request or a real process of the facade).

To work on the dashboard, run it in the workbench application with `composer serve` (http://127.0.0.1:8000/agentio). The served workbench app does not see the variables of your shell: copy `workbench/.env.example` to `workbench/.env` (git-ignored) and fill in `YOUTRACK_URL`, `YOUTRACK_TOKEN`, `AGENTIO_PROJECT` and `AGENTIO_LOGS_PATH` (e.g. the `storage/logs/agents` directory of a project that runs the loop). `composer serve` refreshes the workbench copy of that file on every start; `composer clear` removes it.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently, and the [releases](https://github.com/Alexxosipov/agentio/releases) on GitHub. How a release is made is described in the [contributing guide](.github/CONTRIBUTING.md#releasing).

## Contributing

Thank you for considering contributing to Agentio! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Alexxosipov](https://github.com/Alexxosipov)
- [All Contributors](../../contributors)

## License

Agentio is open-sourced software licensed under the [MIT license](LICENSE.md).
