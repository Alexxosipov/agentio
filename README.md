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

A human files an idea in YouTrack and accepts the result. Everything in between is done by Claude Code agents: requirements (project manager), system analysis in 7 layers (analyst), the design of each epic (architect), stories and tasks with dependencies, code and tests (developer subagents, in parallel when files do not overlap), story reviews (reviewer) and a full test run. Every epic is worked on in its own git worktree and branch `epic/<ID>-<slug>`; YouTrack is the only source of truth, so work resumes from any point after a crash.

The package installs the whole setup into a Laravel project — skills, subagents, permission settings and a guard hook for Claude Code, the loop scripts, the `CLAUDE.md` rules and a user manual — configures the YouTrack project, runs the loop and shows its state in the terminal and in a dashboard.

## Requirements

- PHP 8.3+ with `curl` and `mbstring` (`posix`, `pcntl` and `intl` recommended), Laravel 12 or 13.
- git, Composer, `setsid` (util-linux; on macOS `brew install util-linux`), bun or npm when the project has a frontend build.
- [Claude Code](https://docs.claude.com/claude-code), logged in.
- A YouTrack instance (Cloud or Server with the REST API and the MCP endpoint) and a permanent token.

`php artisan agentio:install` checks all of this and tells you what is missing.

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
- the knowledge base tree: product overview, system analysis with its 7 layers, architecture (overview, data model, ADR, ADR-001), development process, the automation guide (a copy of `docs/AUTONOMOUS_WORKFLOW.md`) and the glossary. New articles are short templates; existing articles with the same title are not changed.

The article ids are written to `.agentio.json` and into the installed skills.

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

The process dashboard is served at `/agentio` (`AGENTIO_UI_PATH`, `AGENTIO_UI_DOMAIN`, disabled with `AGENTIO_UI_ENABLED=false`): the loop and its sessions, what each agent is doing, the pipeline of every idea and epic, blocked issues and the `[AGENT:*]` feed, with links to YouTrack. It needs no frontend build in the host project; publish the views with `php artisan vendor:publish --tag=agentio-views` to customise them.

In the `local` environment everyone may open it; elsewhere only the emails listed in `AGENTIO_ALLOWED_EMAILS` (comma separated). Define your own `viewAgentio` gate, or replace the check entirely:

```php
use Illuminate\Http\Request;
use Obrazmisli\Agentio\Agentio;

if (class_exists(Agentio::class)) {
    Agentio::auth(fn (Request $request): bool => $request->user()?->isAdmin() === true);
}
```

## Configuration

Publish the config with `php artisan vendor:publish --tag=agentio-config` (`agentio:install` does it for you). The most used settings:

| Variable | Default | Meaning |
|---|---|---|
| `YOUTRACK_URL`, `YOUTRACK_TOKEN` | — | YouTrack instance and permanent token (read only from the environment) |
| `AGENTIO_PROJECT` | `TP` | YouTrack project short name |
| `AGENTIO_BASE_BRANCH` | `main` | Base branch of the epic branches |
| `AGENTIO_MERGE_POLICY` | `MERGE_POLICY:` line of `CLAUDE.md` | `local-branch`, `pull-request` or `auto-merge` |
| `AGENTIO_MAX_PARALLEL`, `AGENTIO_MAX_PARALLEL_TASKS` | 2, 2 | Epics at the same time; task subagents per epic |
| `AGENTIO_INTERVAL` | 300 | Seconds between passes |
| `AGENTIO_WORKTREES_PATH` | `../worktrees` | Where epic worktrees are created |
| `AGENTIO_CLAUDE_BIN` | `claude` | Claude Code executable |
| `AGENTIO_TEST_COMMAND` | `php artisan test --compact` | Narrow test run of `scripts/run-tests.sh` (gets its arguments) |
| `AGENTIO_FULL_TEST_COMMAND` | `composer test` (when defined) | Full quality gate (`RUN_TESTS_FULL=1 scripts/run-tests.sh`) |
| `AGENTIO_UI_*`, `AGENTIO_ALLOWED_EMAILS` | | Dashboard path, domain, polling, access |

`scripts/epic-worktree.sh` prepares each worktree with its own `.env` (SQLite database, cache/queue/session prefixes, `APP_URL` port), `composer install`, migrations and — when there is a `bun.lock` or `package-lock.json` and a `build` script — the frontend build. Add project-specific steps (seeders, services) in an executable `scripts/epic-worktree.local.sh`.

The full manual for the people running the cycle is installed as `docs/AUTONOMOUS_WORKFLOW.md` (and copied into the YouTrack knowledge base by `--youtrack`).

## Security

- Headless agents run with `--permission-mode dontAsk`: only the commands allowed in `.claude/settings.json` run; `git push` to the base branch, force pushes, history rewrites, `composer require` and similar are denied. `.claude/agent-settings.json` forbids the agents to edit their own settings, hooks and loop scripts.
- `.claude/hooks/guard-bash.php` is a second line of defence for every Bash command: protected branches, destructive commands outside the project, access to secrets and to the token in the environment.
- The YouTrack token is read only from the environment or `.env`; it is passed to the loop in its environment and to the agents' MCP config by reference (`${YOUTRACK_TOKEN}`), never written into files, logs or comments. `.env.example` gets an empty `YOUTRACK_TOKEN=`.
- Agents commit only the files of their task (`scripts/agent-commit.sh`) and never merge into the base branch; humans accept epics (unless you choose `auto-merge`).
- Keep the dashboard behind the `viewAgentio` gate; it shows issue data and agent logs.

## Testing

```bash
composer test
```

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
