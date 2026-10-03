<div align="center">
    <h1>Agentio</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/obrazmisli/agentio"><img src="https://img.shields.io/packagist/v/obrazmisli/agentio.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/obrazmisli/agentio"><img src="https://img.shields.io/packagist/php-v/obrazmisli/agentio.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/obrazmisli/agentio"><img src="https://badge.laravel.cloud/badge/obrazmisli/agentio?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/obrazmisli/agentio/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/obrazmisli/agentio/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/obrazmisli/agentio"><img src="https://img.shields.io/packagist/dt/obrazmisli/agentio.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Autonomous agentic development cycle for Laravel: YouTrack as the source of truth, Claude Code as PM, analyst, architect, developer and reviewer.

A human files an idea in YouTrack and accepts the result. Everything in between is done by Claude Code agents: requirements (project manager), system analysis kept per product module and feature (analyst), the design of each epic (architect), stories and tasks with dependencies, code and tests (developer subagents, in parallel when files do not overlap), story reviews (reviewer) and a full test run. Every epic is worked on in its own git worktree and branch `epic/<ID>-<slug>`; YouTrack is the only source of truth, so work resumes from any point after a crash.

The package installs the whole setup into a Laravel project — skills, subagents, permission settings and a guard hook for Claude Code, the loop scripts, the `CLAUDE.md` rules and a user manual — configures the YouTrack project, runs the loop and shows its state in the terminal and in a dashboard.

## Requirements

- PHP 8.3+ with `curl` and `mbstring` (`posix`, `pcntl` and `intl` recommended), Laravel 12 or 13.
- git, Composer, `setsid` (util-linux; on macOS `brew install util-linux`), bun or npm when the project has a frontend build.
- [Claude Code](https://docs.claude.com/claude-code), logged in.
- A YouTrack instance (Cloud or Server with the REST API and the MCP endpoint) and a permanent token.

`php artisan agentio:install` checks all of this and tells you what is missing.

## Quick start

1. Install the package as a dev dependency: `composer require obrazmisli/agentio --dev`.
2. Install the cycle into the project (the project must be a git repository): `php artisan agentio:install --project=ABC`, where `ABC` is the short name of your YouTrack project.
3. Put the connection into `.env`: `YOUTRACK_URL=https://example.youtrack.cloud`, `YOUTRACK_TOKEN=perm-...` (never commit it), `AGENTIO_PROJECT=ABC`.
4. Configure the YouTrack project: `php artisan agentio:install --youtrack --dry-run` shows the plan, `php artisan agentio:install --youtrack` applies it and writes the knowledge base article ids into the installed skills.
5. Commit the installed files to the base branch (`.claude/`, `scripts/`, `docs/`, `CLAUDE.md`, `.gitignore`, `.env.example`, `.agentio.json`, `config/agentio.php`): epic worktrees are created from it.
6. File an idea in YouTrack (Type `Idea` or the `idea` tag, State `Backlog`), check what the loop would do with `php artisan agentio:run --dry-run`, then start it: `php artisan agentio:run`.
7. Watch the agents at `/agentio` or with `php artisan agentio:status`, answer `[AGENT:BLOCKED]` comments, and accept epics that reach `Review` by merging their `epic/<ID>-<slug>` branch.

## Installation

```bash
composer require obrazmisli/agentio --dev
```

Install it as a **dev dependency**: the cycle runs on a developer machine (Claude Code, git worktrees, the loop scripts) and nothing of it is needed in production; it also keeps the dashboard, which shows YouTrack data and agent logs, out of production builds. Require it without `--dev` only if you want the dashboard on a shared or staging server. If you call `Agentio::auth()` from a service provider, guard it with `class_exists(Agentio::class)` so that `composer install --no-dev` keeps working.

For local development of the package, use a path repository in the host project's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../agentio", "options": { "symlink": true } }
]
```

```bash
composer require obrazmisli/agentio:@dev --dev
```

## Setting up a project

```bash
php artisan agentio:install --project=ABC                # files for the agents, CLAUDE.md, .gitignore, .env.example, config
php artisan agentio:install --youtrack --dry-run         # plan the YouTrack setup (changes nothing)
php artisan agentio:install --youtrack                   # apply it and record the knowledge base ids
```

Then set the connection in `.env` (never commit the token):

```dotenv
YOUTRACK_URL=https://example.youtrack.cloud
YOUTRACK_TOKEN=perm-...
AGENTIO_PROJECT=ABC
```

and follow the checklist the command prints: connect the YouTrack MCP server for your interactive sessions (`claude mcp add --transport http youtrack https://example.youtrack.cloud/mcp --header "Authorization: Bearer <token>"`), create a Kanban board by the `Stage` field if you want one, **commit the installed files to the base branch** (epic worktrees take `.claude/` and `scripts/` from it) and try `php artisan agentio:run --dry-run`.

### `agentio:install`

| Option | Meaning |
|---|---|
| `--project=KEY` | YouTrack project short name. Default: `.agentio.json`, then `AGENTIO_PROJECT` |
| `--base-branch=BRANCH` | Branch epic branches start from and humans merge into. Default: `.agentio.json`, then `AGENTIO_BASE_BRANCH` (`main`) |
| `--merge-policy=POLICY` | `local-branch` (the branch stays local), `pull-request` (push + PR via `gh`) or `auto-merge`. Default: `AGENTIO_MERGE_POLICY`, then the `MERGE_POLICY:` line of `CLAUDE.md`, then `local-branch` |
| `--youtrack` | Also configure the YouTrack project (see below) |
| `--force` | Overwrite installed files that were edited locally |
| `--dry-run` | Only print the plan |

What it installs (the stubs live in `stubs/`; `{{project}}`, `{{base_branch}}`, `{{merge_policy}}` and `{{kb.<key>}}` placeholders are rendered on the way):

- `.claude/skills/` — `youtrack-workflow` (process rules, templates, search queries, knowledge base map), `project-manager`, `system-analyst`, `laravel-architect`, and the commands `/plan`, `/work-epic`, `/dispatch`, `/status`;
- `.claude/agents/` — `task-developer` and `story-reviewer` subagents;
- `.claude/hooks/guard-bash.php`, `.claude/agent-settings.json`, `.claude/agents-mcp.json` (YouTrack MCP from the environment; Laravel Boost only when the project uses it);
- `.claude/settings.json` — **merged** with yours: allow/deny lists and enabled MCP servers are united, the guard hook is added once, everything else is kept;
- `scripts/` — `agent-loop.sh`, `epic-worktree.sh`, `yt.php`, `agent-commit.sh`, `agent-log.php`, `run-tests.sh`, `php/testing.ini`;
- `docs/AUTONOMOUS_WORKFLOW.md` — the user manual of the cycle;
- a block between `<!-- agentio:start -->` and `<!-- agentio:end -->` at the top of `CLAUDE.md` (replaced on reinstall, the rest of the file is untouched);
- `/.agent-stop` and `/storage/logs/agents` in `.gitignore`, `YOUTRACK_URL=`, `YOUTRACK_TOKEN=`, `AGENTIO_PROJECT=` in `.env.example`, and `config/agentio.php` when it is not published yet.

The command is idempotent and safe to rerun after updating the package: a file with the expected content is left alone, a file that still has the content of the previous install is updated, and a file you edited is skipped with a warning (`--force` overwrites it). `.agentio.json` (commit it) remembers the project, the base branch, the knowledge base article ids and the hashes of the installed files.

`--youtrack` configures the project through the REST API, finding everything by name first and never deleting or renaming anything:

- the `State` field (Backlog, Analysis, Ready, In Progress, Review, Blocked, Done) — when the project already has a `State` field with another bundle, only the missing values are added to it;
- the `Type` field (Idea, Epic, Story, Task) and the `Stage` field (Backlog, Develop, Review, Test, Staging, Done — the Kanban columns, derived from State by `scripts/yt.php`);
- the `idea` and `agent-claimed` tags and the `KEY: …` saved searches;
- the knowledge base tree: product overview, system analysis (the root with the module map and «Общие требования»), architecture (overview, data model, ADR, ADR-001), development process, the automation guide (a copy of `docs/AUTONOMOUS_WORKFLOW.md`) and the glossary. New articles are short templates; existing articles with the same title are not changed.

The article ids are written to `.agentio.json` and into the installed skills.

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

`--youtrack` creates only the root and «Общие требования»; the `system-analyst` skill adds modules and features as the product grows (a small change edits an existing feature article, a new capability becomes a new feature article, a new product area becomes a module and a row in the module map). An entity is described in full once, in the feature that introduced it; the architect's data model article links to it. `php scripts/yt.php kb-tree [<ARTICLE>] [--depth=N] [--json]` prints the tree with article ids; agents create articles under their parent with the `create_article` tool of the YouTrack MCP server (`parentArticle`).

Projects set up by an earlier version have the seven layer articles («1. Бизнес-контекст и цели» … «7. Ограничения, допущения и риски») under «Системная аналитика». Rerunning `agentio:install --youtrack` deletes, renames and moves nothing: it only adds «Общие требования» under the existing root. The layer articles stay as a read-only archive — the analyst moves their content into feature articles whenever it works on the corresponding feature, and rewrites the root (module map plus a list of the archived layer articles) the first time it runs. The old skill file `.claude/skills/system-analyst/layers.md` is no longer used; delete it after updating.

## Running the loop

```bash
php artisan agentio:run --dry-run       # what would be started: ideas, ready epics, first wave of tasks, blocked issues
php artisan agentio:run --once          # one pass, waiting for the started epic sessions
php artisan agentio:run                 # run forever (a pass every AGENTIO_INTERVAL seconds)
php artisan agentio:run --stop          # ask the running loop to exit after its current step (.agent-stop)
php artisan agentio:run --kill          # stop running agent sessions (claims are kept and resumed later)
```

`agentio:run` runs the installed `scripts/agent-loop.sh` with the settings of `config/agentio.php` in its environment (`YOUTRACK_URL`, `YOUTRACK_TOKEN`, `AGENTIO_PROJECT`, `BASE_BRANCH`, `MAX_PARALLEL`, `MAX_PARALLEL_TASKS`, `AGENT_LOOP_INTERVAL`, `WORKTREES_DIR`, `CLAUDE_BIN`, `MERGE_POLICY`, `AGENT_LOG_DIR`, the test commands), so the connection can live in `.env`. Options passed through to the script: `--once`, `--dry-run`, `--kill`, `--epic=KEY-N`, `--no-plan`, `--no-wait`, `--interval=SEC`, `--max-parallel=N`, `--max-parallel-tasks=N`. A leftover stop flag is reported; `--fresh` removes it before starting. The output is passed through (on a TTY directly, otherwise line by line), SIGINT and SIGTERM are forwarded to the loop (it finishes its current step; agent sessions keep running), and the exit code is the loop's. The loop keeps `storage/logs/agents/loop.pid` while it runs and refuses to start twice in one checkout.

### `agentio:status`

```bash
php artisan agentio:status              # loop, live sessions with their last event, YouTrack summary
php artisan agentio:status --json
php artisan agentio:status --local      # without YouTrack
```

The YouTrack part shows counts by Type × State, ideas waiting for planning, ready epics with their first wave of tasks, work in progress, blocked issues with the reason from their last `[AGENT:BLOCKED]` comment, and epics awaiting a human.

## Dashboard

The process dashboard is served at `/agentio` (`AGENTIO_UI_PATH`, `AGENTIO_UI_DOMAIN`, disabled with `AGENTIO_UI_ENABLED=false`). It is a Blade page with the package's own script and stylesheet (served by the package, no CDN, no frontend build in the host project) that polls JSON endpoints every `AGENTIO_UI_POLL` seconds and pauses while the browser tab is hidden. Publish the view with `php artisan vendor:publish --tag=agentio-views` to customise it.

Screens:

- **Overview** (`#/`): the loop status in the header (running / stopping / stopped, live sessions, merge policy, YouTrack connection); *who works now* — every live Claude Code session with its issue, pipeline stage, the tasks being worked on, its latest tool calls, subagents and messages, plus the issues claimed by agents and the last finished sessions with their cost and duration; the *pipeline* of every idea and epic through the stages Idea → Requirements and analysis → Architecture → Decomposition → Development → Story review → Acceptance → Done, with task progress and the reason of a blocked item (its last `[AGENT:BLOCKED]`); the `[AGENT:*]` *event feed*; the tail of `loop.log`.
- **Kanban** (`#/board`): every issue of the project in a column per State, filtered by epic, type or text, with claims and unmet dependencies.
- **Loop log** (`#/log`): the last lines of `loop.log`.
- **Epic** (`#/epic/<ID>`): the stage and progress of an epic (or an idea), its STORY → TASK tree with readiness and dependencies, the progress of each story, the tasks ready to be taken, the tasks waiting for dependencies and the `[AGENT:*]` feed of the whole tree.

Every issue id links to YouTrack. Without YouTrack (not configured or not reachable) the page keeps showing the local sessions and the loop log and says what is missing.

| Endpoint (GET) | Returns |
|---|---|
| `/agentio` | The page |
| `/agentio/assets/app.css`, `/agentio/assets/app.js` | The stylesheet and the script (cached forever with their `?v=` content hash) |
| `/agentio/api/status` | Project, loop status and pid, stop flag, live sessions count, merge policy, logs directory, YouTrack connection |
| `/agentio/api/sessions` | Live sessions with their issue, stage, current tasks and latest events; recently finished sessions; claimed issues |
| `/agentio/api/pipeline` | Ideas and epics with their pipeline stage, progress and blocking reason |
| `/agentio/api/board` | Kanban columns by State and the list of epics |
| `/agentio/api/events` | The latest `[AGENT:*]` comments of the project |
| `/agentio/api/loop-log` | The last lines of `loop.log` |
| `/agentio/api/epics/{id}` | One epic or idea in detail (404 when YouTrack does not know the issue) |

The data comes from YouTrack (cached for `AGENTIO_UI_CACHE` seconds, so polling browsers do not hit YouTrack on every request; a failure is cached for at least 30 seconds, so an unreachable YouTrack does not hold up every poll) and from the files the loop writes to `AGENTIO_LOGS_PATH` (pid files, `loop.log`, stream-json session logs) plus the `.agent-stop` flag. The YouTrack token never reaches the browser: should a session log or an error message contain it, it is replaced with `[redacted]`.

### Access

Every route of the dashboard, the assets included, goes through the `ui.middleware` (`web` by default) and the `viewAgentio` gate. In the `local` environment everyone may open it; elsewhere only an authenticated user whose email is listed in `AGENTIO_ALLOWED_EMAILS` (comma separated), everybody else gets 403. To change the rule, define your own `viewAgentio` gate in a service provider, or replace the check entirely with `Agentio::auth()` (`Agentio::auth(null)` restores the gate):

```php
use Illuminate\Http\Request;
use Obrazmisli\Agentio\Agentio;

if (class_exists(Agentio::class)) {
    Agentio::auth(fn (Request $request): bool => $request->user()?->isAdmin() === true);
}
```

## Configuration

Publish the config with `php artisan vendor:publish --tag=agentio-config` (`agentio:install` does it for you). Everything can be set from `.env`:

| Variable | Config key | Default | Meaning |
|---|---|---|---|
| `YOUTRACK_URL`, `YOUTRACK_TOKEN` | `youtrack.url`, `youtrack.token` | — | YouTrack instance and permanent token (read only from the environment) |
| `AGENTIO_PROJECT` | `youtrack.project` | the project in `.agentio.json`, then `TP` | YouTrack project short name (also read by `scripts/yt.php` from `.env`) |
| `AGENTIO_YOUTRACK_TIMEOUT` | `youtrack.timeout` | 30 | Seconds per YouTrack request |
| `AGENTIO_YOUTRACK_RETRIES` | `youtrack.retries` | 2 | Retries of a request after a connection error, a 5xx or a 429 |
| `AGENTIO_BASE_BRANCH` | `base_branch` | the branch in `.agentio.json`, then `main` | Base branch of the epic branches |
| `AGENTIO_MERGE_POLICY` | `merge_policy` | the `MERGE_POLICY:` line of `CLAUDE.md` | `local-branch`, `pull-request` or `auto-merge` |
| `AGENTIO_MAX_PARALLEL`, `AGENTIO_MAX_PARALLEL_TASKS` | `max_parallel`, `max_parallel_tasks` | 2, 2 | Epics at the same time; task subagents per epic |
| `AGENTIO_INTERVAL` | `interval` | 300 | Seconds between passes of the loop |
| `AGENTIO_WORKTREES_PATH` | `worktrees_path` | `../worktrees` | Where epic worktrees are created |
| `AGENTIO_CLAUDE_BIN` | `claude_binary` | `claude` | Claude Code executable |
| `AGENTIO_TEST_COMMAND` | `tests.command` | `php artisan test --compact` | Narrow test run of `scripts/run-tests.sh` (gets its arguments) |
| `AGENTIO_FULL_TEST_COMMAND` | `tests.full_command` | `composer test` (when defined) | Full quality gate (`RUN_TESTS_FULL=1 scripts/run-tests.sh`) |
| `AGENTIO_LOGS_PATH` | `logs_path` | `storage/logs/agents` | Where the loop writes `loop.log`, `loop.pid`, `<ID>.pid`/`<ID>.log` (epic sessions) and `plan-<ID>.pid`/`plan-<ID>.log` (planning sessions); `agentio:run` passes it to the loop; the dashboard, `agentio:status` and `scripts/agent-log.php` read it |
| `AGENTIO_TIMEZONE` | `timezone` | the machine's time zone | Time zone of the local timestamps the loop writes (`loop.log`, session headers); detected from `$TZ`, `/etc/timezone` or `/etc/localtime` when empty |
| — | `stage_map` | see the file | State → Stage map of the Kanban board field |
| `AGENTIO_UI_ENABLED` | `ui.enabled` | `true` | Register the dashboard routes |
| `AGENTIO_UI_PATH`, `AGENTIO_UI_DOMAIN` | `ui.path`, `ui.domain` | `agentio`, — | Where the dashboard is mounted |
| — | `ui.middleware` | `['web']` | Middleware of the dashboard routes (the `viewAgentio` check is always added) |
| `AGENTIO_UI_POLL` | `ui.poll` | 5 | Seconds between the page's requests |
| `AGENTIO_UI_CACHE` | `ui.cache` | 5 | Seconds YouTrack answers are cached for the dashboard (0 disables the cache) |
| `AGENTIO_ALLOWED_EMAILS` | `ui.allowed_emails` | — | Emails allowed to open the dashboard outside the `local` environment |

`scripts/epic-worktree.sh` prepares each worktree with its own `.env` (SQLite database, cache/queue/session prefixes, `APP_URL` port), `composer install`, migrations and — when there is a `bun.lock` or `package-lock.json` and a `build` script — the frontend build. Add project-specific steps (seeders, services) in an executable `scripts/epic-worktree.local.sh`.

The full manual for the people running the cycle is installed as `docs/AUTONOMOUS_WORKFLOW.md` (and copied into the YouTrack knowledge base by `--youtrack`).

## Security

- Headless agents run with `--permission-mode dontAsk`: only the commands allowed in `.claude/settings.json` run; `git push` to the base branch, force pushes, history rewrites, `composer require` and similar are denied. The loop passes these rules together with `.claude/agent-settings.json` through `--settings`, because Claude Code ignores the allow rules of a project directory whose workspace trust was never accepted, and every new epic worktree is such a directory. `.claude/agent-settings.json` forbids the agents to edit their own settings, hooks, loop scripts and `.agentio.json`.
- `.claude/hooks/guard-bash.php` is a second line of defence for every Bash command: protected branches, destructive commands outside the project, access to secrets and to the token in the environment.
- The YouTrack token is read only from the environment or `.env`; it is passed to the loop in its environment and to the agents' MCP config by reference (`${YOUTRACK_TOKEN}`), never written into files, logs or comments. `.env.example` gets an empty `YOUTRACK_TOKEN=`.
- Agents commit only the files of their task (`scripts/agent-commit.sh`) and never merge into the base branch; humans accept epics (unless you choose `auto-merge`).
- Keep the dashboard behind the `viewAgentio` gate; it shows issue data and agent logs.

## Testing

```bash
composer test
```

`composer test` runs PHPStan, Pint, the 100 % type coverage check and the Pest suite (YouTrack is always faked there).

To work on the dashboard, run it in the workbench application with `composer serve` (http://127.0.0.1:8000/agentio). The served workbench app does not see the variables of your shell: copy `workbench/.env.example` to `workbench/.env` (git-ignored) and fill in `YOUTRACK_URL`, `YOUTRACK_TOKEN`, `AGENTIO_PROJECT` and `AGENTIO_LOGS_PATH` (e.g. the `storage/logs/agents` directory of a project that runs the loop). `composer serve` refreshes the workbench copy of that file on every start; `composer clear` removes it.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Agentio! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Alexxosipov](https://github.com/obrazmisli)
- [All Contributors](../../contributors)

## License

Agentio is open-sourced software licensed under the [MIT license](LICENSE.md).
