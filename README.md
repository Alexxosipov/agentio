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

- PHP 8.3+ with `mbstring` (`posix` and `pcntl` recommended), Laravel 12 or 13.
- git, Composer, `setsid` (util-linux; on macOS `brew install util-linux`), bun or npm when the project has a frontend build.
- [Claude Code](https://docs.claude.com/claude-code), logged in.
- A YouTrack instance with its MCP server (`<url>/mcp`, YouTrack Cloud or a Server version that has it) and a permanent token.

`php artisan agentio:install` checks all of this and tells you what is missing.

## Quick start

1. Install the package from GitHub as a dev dependency (see [Installation](#installation): add the repository to `composer.json`, then `composer require obrazmisli/agentio:^0.1 --dev`).
2. Install the cycle into the project (a git repository whose `.env` is git-ignored): `php artisan agentio:install`. It asks for the YouTrack URL and a permanent token (hidden input), checks them through the YouTrack MCP server, adds the `youtrack` MCP server to Claude Code if it has none, asks for the project short name (`ABC`; a missing project can be created), the production and the development branch (`main` and `dev`; the missing one is created locally), the merge policy and **the directory of the epic worktrees**, offers to configure the YouTrack project and installs the skills.
3. Commit `.claude/skills/agentio-*` and `.agentio.json` to `dev`: epic worktrees are created from it. `.env` (with the token) stays local. Push `dev` and `main` if the project has a remote, and point the develop server at `dev` and production at `main`.
4. File an idea in YouTrack (Type `Idea` or the `idea` tag, Stage `Backlog`), check what the loop would do with `php artisan agentio:run --dry-run`, then start it: `php artisan agentio:run`.
5. Watch the agents at `/agentio` or with `php artisan agentio:status`, answer `[AGENT:BLOCKED]` comments, and accept epics that reach `Review` on their page in the dashboard or with `php artisan agentio:accept <ID>` (it merges the epic branch into `dev`). Release `dev` to `main` yourself when the develop server looks good.

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
composer require obrazmisli/agentio:^0.1 --dev
```

Install it as a **dev dependency**: the cycle runs on a developer machine (Claude Code, git worktrees, the loop) and nothing of it is needed in production; it also keeps the dashboard, which shows YouTrack data and agent logs, out of production builds. Require it without `--dev` only if you want the dashboard on a shared or staging server. If you call `Agentio::auth()` from a service provider, guard it with `class_exists(Agentio::class)` so that `composer install --no-dev` keeps working.

Composer reads the tags through the GitHub API. Without a token it is limited to 60 requests an hour, and on a busy machine or CI it may ask for one: create a token without scopes at https://github.com/settings/tokens and run `composer config --global github-oauth.github.com <token>`.

### Updating

```bash
composer update obrazmisli/agentio
php artisan agentio:install           # refreshes the installed skills; your edits are kept
php artisan agentio:setup-youtrack    # refreshes the automation guide in the knowledge base
```

Commit the updated `.claude/skills/agentio-*` and `.agentio.json`. While the version is `0.x`, `^0.1` takes only the `0.1.*` releases: a `0.2.0` may change behaviour (see the [changelog](CHANGELOG.md)), so move to it with `composer require obrazmisli/agentio:^0.2 --dev`. To try the latest unreleased code, require `dev-main`.

A project that has a copy of the package (a `path` repository such as `packages/agentio`) switches by replacing that repository with the `vcs` one above, running `composer require obrazmisli/agentio:^0.1 --dev` and deleting the copy.

For local development of the package, use a path repository in the host project's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../agentio", "options": { "symlink": true } }
]
```

```bash
composer require obrazmisli/agentio:@dev --dev
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
| `.env` | `YOUTRACK_URL`, `YOUTRACK_TOKEN`, `AGENTIO_WORKTREES_PATH` (local, git-ignored) |
| Claude Code (`~/.claude.json`) | The `youtrack` MCP server with your token (local scope: this project only, private) |

Nothing else: no scripts, hooks, subagents, `.claude/settings.json` or `.mcp.json` changes, no `CLAUDE.md` block. Everything else is in the package and is reached through artisan:

| Package path | Used by |
|---|---|
| `scripts/agent-loop.sh`, `epic-worktree.sh`, `agent-commit.sh`, `run-tests.sh` | `agentio:run`, `agentio:worktree`, `agentio:commit`, `agentio:test` (the scripts refuse to run on their own) |
| `resources/claude/settings.json`, `planning.json` | Permission rules of the agents' epic and planning sessions (passed with `--settings`, with the guard hook) |
| `bin/agentio-guard` | The PreToolUse hook of the agents' Bash commands |
| `resources/claude/mcp/*.json` | MCP servers of the headless sessions (`youtrack`, `laravel-boost` when the project uses Boost) |
| `resources/docs/AUTONOMOUS_WORKFLOW.md` | The manual (copied into the YouTrack knowledge base by `agentio:setup-youtrack`) |

### Skills

Packaged as Claude Code skills (a `SKILL.md` with `name` and `description` under 500 lines each, details in `references/` one level deep), all prefixed with `agentio-` so they never clash with the project's own skills or with built-in commands such as `/plan` and `/status`:

| Skill | Kind | Purpose |
|---|---|---|
| `agentio-youtrack-workflow` | background (`user-invocable: false`) | Process rules: hierarchy, Stage, readiness, claims, `[AGENT:*]` comments, MCP tools, queries, knowledge base map |
| `agentio-project-manager`, `agentio-system-analyst`, `agentio-laravel-architect` | roles | Requirements and decomposition; system analysis per module and feature; epic design and ADR |
| `/agentio-plan <ID or text>` | command (`disable-model-invocation`) | Full planning of an idea |
| `/agentio-work-epic <EPIC>` | command (`disable-model-invocation`) | Epic orchestrator: starts subagents with the next two skills |
| `agentio-develop-task`, `agentio-review-story` | used by subagents | One TASK: code, tests, commit; one STORY: review against acceptance criteria |
| `/agentio-dispatch`, `/agentio-status` | read-only commands | What can start now; a summary of the project |

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
| `--force` | Overwrite installed skills that were edited locally |
| `--dry-run` | Only print the plan |

The token is never printed. The command is idempotent and safe to rerun after updating the package: a skill file with the expected content is left alone, one that still has the content of the previous install is updated, one you edited is skipped with a warning (`--force` overwrites it), and a skill file of an earlier version that the package no longer ships is removed unless you edited it.

### `agentio:setup-youtrack`

Configures the YouTrack project for the cycle. It can run any number of times: everything is looked up first and **nothing is created twice**; nothing is deleted or moved, and only the fields and values below are renamed.

- **Fields.** The cycle uses the fields YouTrack gives new projects — **Stage** (the status of an issue) and **Type** («Тип») — and never creates a second field of the same meaning: a field is found by its name or by its localized name, and only the missing values are added to its bundle (Stage: Backlog, Analysis, Ready, In Progress, Review, Blocked, Done, next to YouTrack's own Develop, Test, Staging, which the cycle does not use; Type: Idea, Epic, Story, Task). New issues default to Stage `Backlog` and Type `Task` when the field has no default among these values (YouTrack's `Submitted` would hide an idea from the loop). A bundle shared with other projects, or the default bundle YouTrack gives new projects, is never changed: a project without issues is switched to its own `KEY Stages` / `KEY Types`, a project with issues gets a warning.
- **English names.** The agents read the values through the YouTrack MCP server, which answers with the localized name of a value: a YouTrack in Russian shows `Backlog` as «Очередь», `Done` as «Готово», and the cycle would not recognize them. So in the project's own bundles the cycle's values lose their localized name (they are shown as `Backlog`, `Review`, `Done`, …), a value named in Russian («Очередь», «В работе», «Задача», …) is renamed to the cycle's name (its issues keep it), and a Stage or Type field named in Russian («Этап», «Тип») is renamed to `Stage` / `Type` — a global field, so every project that uses it sees the new name. YouTrack's own values the cycle does not use (Develop, Test, Staging) keep their localized names.
- **No State field.** Status is Stage only; build the board columns on Stage. A `State` field («Состояние») of the project is reported and left as is. In a project set up by an earlier agentio version the status lives in State: copy it to Stage (a bulk update by `State: <value>` in YouTrack).
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
├── Пользователи           a module: purpose, scope, roles, features, entity index, dependencies on other modules
│   ├── Регистрация        a feature: goal, scenarios, FR/BR, data model, interfaces, NFR, risks — only what applies
│   └── Профиль и аватар
└── …
```

`agentio:setup-youtrack` creates only the root and «Общие требования»; the `agentio-system-analyst` skill adds modules and features as the product grows. `php artisan agentio:yt kb-tree [<ARTICLE>] [--depth=N] [--json]` prints the tree with article ids; agents create articles under their parent with the `create_article` tool of the MCP server (`parentArticle`). Articles of the earlier per-layer structure («1. Бизнес-контекст и цели» … «7. Ограничения, допущения и риски») are kept as a read-only archive; the analyst moves their content into feature articles.

## Running the loop

```bash
php artisan agentio:run --dry-run       # what would be started: ideas, ready epics, their trees, blocked issues
php artisan agentio:run --once          # one pass, waiting for the started epic sessions
php artisan agentio:run                 # run forever (a pass every AGENTIO_INTERVAL seconds)
php artisan agentio:run --stop          # ask the running loop to exit after its current step
php artisan agentio:run --kill          # stop running agent sessions (claims are kept and resumed later)
```

`agentio:run` runs the loop of the package (`scripts/agent-loop.sh`) with the settings of `config/agentio.php` and `.agentio.json` in its environment. The variables Laravel loaded from the project's `.env` (`APP_ENV`, `APP_KEY`, `DB_*`, …) are removed from that environment: inherited by the agents, they would override the `.env` of every epic worktree and the `<env>` values of `phpunit.xml`. Options passed through: `--once`, `--dry-run`, `--kill`, `--epic=KEY-N`, `--no-plan`, `--no-wait`, `--interval=SEC`, `--max-parallel=N`, `--max-parallel-tasks=N`. A leftover stop flag is reported; `--fresh` removes it before starting. SIGINT and SIGTERM are forwarded to the loop (it finishes its current step; agent sessions keep running), and the exit code is the loop's. The loop keeps `loop.pid` in the logs directory while it runs and refuses to start twice in one checkout.

Each pass starts the epics that can go on (claimed by this machine and interrupted, or ready) — preparing the worktree with `agentio:worktree` and starting `/agentio-work-epic <EPIC>` in it — and plans the ideas one at a time with `/agentio-plan <IDEA>` (an interrupted planning first). What this machine left unfinished is computed by `agentio:yt resumable`. The loop does not wait for a planning session, and when any session finishes it starts the next pass right away instead of waiting for the interval. Before starting an epic it checks that the skills are committed to the development branch.

A session that ends without finishing its work (an epic not in `Review`, `Blocked` or `Done`, an idea not planned) is counted in `<NAME>.restarts`; new commits on the epic branch reset the count. After `MAX_RESTARTS` (3) such sessions in a row the issue gets an `[AGENT:BLOCKED]` comment and goes to `Blocked`. A session counts as alive only while its pid is still a Claude Code process, so a pid reused after a reboot does not hold a slot, and `loop.lock` (`flock`) keeps a second loop from starting in the same checkout.

Headless sessions run `claude -p … --permission-mode dontAsk` with everything from the package: `--settings` (the package's session settings merged with the allow/deny rules of the project's own `.claude/settings.json`, if any — Claude Code ignores the allow rules of a directory whose workspace trust was never accepted, and every new epic worktree is such a directory), and `--strict-mcp-config --mcp-config` with the `youtrack` server (and `laravel-boost` when the project uses Boost), whose `${YOUTRACK_URL}` and `${YOUTRACK_TOKEN}` come from the loop's environment.

### Commands of the agents

| Command | Purpose |
|---|---|
| `php artisan agentio:yt <action>` | YouTrack helper over the MCP server (see above) |
| `php artisan agentio:test [args]`, `--full` | Run the tests (`AGENTIO_TEST_COMMAND`, default `php artisan test --compact`) or the full gate (`AGENTIO_FULL_TEST_COMMAND`, default `composer test`); the output goes to `storage/logs/tests/`, the result is printed, Playwright servers left by browser tests are killed |
| `php artisan agentio:commit <TASK> "<message>" <files…>` | Commit only the given files with the `<TASK>: ` prefix, under a repository lock |
| `php artisan agentio:worktree <EPIC> [--remove]` | Create and prepare the worktree of an epic (used by the loop) |
| `php artisan agentio:log <ID>` | Print a session log in a readable form (`--lines`, `--follow`) |

`agentio:worktree` gives each worktree its own `.env` (made from `.env.example`: own `APP_KEY`, SQLite database, cache/queue/session prefixes, `APP_URL` port), runs `composer install`, migrations and — when there is a `bun.lock` or `package-lock.json` and a `build` script — the frontend build. Add project-specific steps (seeders, services) with `AGENTIO_WORKTREE_SETUP`: a shell command run last in the new worktree with the epic id as `$1`.

### `agentio:status`

```bash
php artisan agentio:status              # loop, live sessions with their last event, YouTrack summary
php artisan agentio:status --json
php artisan agentio:status --local      # without YouTrack
```

The YouTrack part shows counts by Type × Stage, ideas waiting for planning, ready epics with their first wave of tasks, work in progress, blocked issues with the reason from their last `[AGENT:BLOCKED]` comment, and epics awaiting a human.

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
| `AGENTIO_TEST_COMMAND` | `tests.command` | `php artisan test --compact` | Narrow test run of `agentio:test` (gets its arguments) |
| `AGENTIO_FULL_TEST_COMMAND` | `tests.full_command` | `composer test` (when defined) | Full quality gate (`agentio:test --full`) |
| `AGENTIO_LOGS_PATH` | `logs_path` | `storage/logs/agents` | Where the loop writes `loop.log`, `loop.pid`, `<ID>.pid`/`<ID>.log` (epic sessions), `plan-<ID>.pid`/`plan-<ID>.log` (planning sessions) and the `stop` flag; the dashboard, `agentio:status` and `agentio:log` read it |
| `AGENTIO_TIMEZONE` | `timezone` | the machine's time zone | Time zone of the local timestamps the loop writes (`loop.log`, session headers); detected from `$TZ`, `/etc/timezone` or `/etc/localtime` when empty |
| `AGENTIO_UI_ENABLED` | `ui.enabled` | `true` | Register the dashboard routes |
| `AGENTIO_UI_PATH`, `AGENTIO_UI_DOMAIN` | `ui.path`, `ui.domain` | `agentio`, — | Where the dashboard is mounted |
| — | `ui.middleware` | `['web']` | Middleware of the dashboard routes (the `viewAgentio` check is always added) |
| `AGENTIO_UI_POLL` | `ui.poll` | 5 | Seconds between the page's requests |
| `AGENTIO_UI_CACHE` | `ui.cache` | 15 | Seconds YouTrack answers are cached for the dashboard (0 disables the cache; keep it above `AGENTIO_UI_POLL`, or every poll goes to YouTrack) |
| `AGENTIO_UI_ACTIONS` | `ui.actions` | true | Whether the dashboard may accept an epic in Review (merge its branch) and send it back for rework |
| `AGENTIO_ALLOWED_EMAILS` | `ui.allowed_emails` | — | Emails allowed to open the dashboard outside the `local` environment |

The full manual for the people running the cycle is `resources/docs/AUTONOMOUS_WORKFLOW.md` of the package; `agentio:setup-youtrack` keeps a copy of it in the YouTrack knowledge base («Руководство по автоматизации»).

## Security

- The agents work with YouTrack only through the MCP server and are not given the token: it lives in `.env` (git-ignored; `agentio:install` checks that) and in Claude Code's own MCP configuration (`~/.claude.json`), is passed to the loop in its environment and to the MCP config of the headless sessions by reference (`${YOUTRACK_TOKEN}`), and is never printed or written into committed files, logs or comments. The worktree `.env` (made from `.env.example`) has no token. The agents run as your OS user, though, with the token in their environment: the rules below refuse the usual ways to read it, but for a hard boundary run the loop in a sandbox or under a separate user, and use a token that can reach only this project.
- Headless agents run with `--permission-mode dontAsk`: only the commands allowed by the session settings run. Epic sessions get the rules of the package plus the project's own; `git push` to the development and the production branch, force pushes, history rewrites, `composer require` and similar are denied, `Read(./.env)` is denied, and so are edits of `.agentio.json`, the agentio skills, `vendor/`, `php -i` and the `tinker` and `get-config` tools of Laravel Boost. Planning sessions run in your main checkout and are read-only (`resources/claude/planning.json`): they read the code and write YouTrack, nothing else.
- `bin/agentio-guard` is the PreToolUse hook of every Bash command of the agents, a second line of defence. It runs from the package of the main checkout without booting the application of the worktree (code the agents edit), takes the protected branches and the extra directories from the loop (never from files of the worktree), and refuses the command when it fails. It splits the command line like a shell (quotes, `$(…)`, pipes, `cd`) and refuses: pushes to protected branches and force pushes, branch moves other than the branches of issues, merges into protected branches, history rewrites, git aliases and `git -c`/`config` writes, `git add -A`/`commit -a`/`--amend`, writes (`rm`, `mv`, `cp`, `sed -i`, `tee`, `>`) outside the project or to the agentio settings, skills and `vendor/`, `find -exec`, access to secrets and to the token (`.env`, `YOUTRACK_TOKEN`, `/proc/*/environ`, `php -i`, `config:show agentio`, `printenv`), and the commands only humans and the loop run (`agentio:install`, `agentio:setup-youtrack`, `agentio:worktree`, `agentio:accept`, `agentio:run` other than `--dry-run`, `tinker`), also behind artisan options such as `-n`.
- Agents commit only the files of their task (`agentio:commit`) and never merge into the development branch; humans accept epics (unless you choose `auto-merge`) and release to production.
- Keep the dashboard behind the `viewAgentio` gate (it shows issue data and agent logs) and give `manageAgentio` (merging into the development branch of the checkout the app runs from) only to the people who accept epics.

## Testing

```bash
composer test
```

`composer test` runs PHPStan, Pint, the 100 % type coverage check and the Pest suite (YouTrack is always faked there).

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
