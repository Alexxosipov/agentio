# Release Notes

## [Unreleased](https://github.com/obrazmisli/agentio/compare/v0.1.0...1.x)

### Changed

- Branch model: `main` is production, `dev` the development branch (the develop server); every epic is worked on in a branch named after its issue id (`TP-12`) instead of `epic/<ID>-<slug>`, started from `dev` and merged back into it. `agentio:install` asks for both branches and creates the missing ones locally. Epics started on an `epic/<ID>-<slug>` branch are resumed and accepted on it. `AGENTIO_BASE_BRANCH` now defaults to `dev`; `agentio:yt slug` and the branch slugs are gone.
- The settings of the headless sessions are built in PHP (`SessionSettings`) and allow pushing only the branches of the project's issues; the loop no longer runs inline PHP for them.
- `agentio:install` adds only skills to the project (`.claude/skills/agentio-*`, packaged as Claude Code skills with the `agentio-` prefix; the subagents became the `agentio-develop-task` and `agentio-review-story` skills) plus `.agentio.json` and `.env` keys. Scripts, hooks, session settings, MCP configs and the manual stay in the package; nothing is merged into `.claude/settings.json`, `.mcp.json`, `CLAUDE.md`, `.gitignore` or `.env.example`.
- The agents work with YouTrack only through its MCP server. `scripts/yt.php` is replaced by `php artisan agentio:yt`, which also talks to the MCP server; the REST API is left to the project setup and the dashboard.
- The loop scripts run only through artisan: `agentio:run`, `agentio:worktree`, `agentio:commit`, `agentio:test` and `agentio:log`.
- The status of an issue is the **Stage** field, the status field YouTrack gives new projects; `agentio:setup-youtrack` adds the cycle's values to its bundle. A `State` field («Состояние») is no longer used: the setup reports it and leaves it as is.
- `agentio:install` checks the token through the MCP server, adds the `youtrack` MCP server to Claude Code with `claude mcp add` when it has none, and asks for the directory of the epic worktrees (`AGENTIO_WORKTREES_PATH`, required; there is no default any more).
- The merge policy is recorded in `.agentio.json` instead of a `MERGE_POLICY:` line of `CLAUDE.md`; the stop flag lives in the logs directory.

### Added

- `AGENTIO_PRODUCTION_BRANCH` (`production_branch`, recorded in `.agentio.json`).
- Planning sessions run with read-only settings (`resources/claude/planning.json`): they work in the developer's main checkout and change no file.
- `php artisan agentio:accept <EPIC>`: the dashboard's acceptance from the command line. The loop uses it for `auto-merge` without a remote, so an auto-merged epic and its stories are now moved to `Done` too.
- Epic acceptance in the dashboard: the epic page shows the commits, changed files and per-file diffs of the epic branch, the merge checks, the final `[AGENT:DONE]` and the story verdicts; an epic in `Review` can be accepted (merge into the base branch, worktree and branch removal, stories and epic to `Done`) or sent back for rework (a TASK with the remark, the story and the epic back to `Ready`). `AGENTIO_UI_ACTIONS=false` makes the dashboard read-only.
- `agentio:setup-youtrack` configures the YouTrack project on its own and can run any number of times without creating anything twice. It uses the project's Stage and Type fields, found by name or localized name.

### Fixed

- The guard hook is `bin/agentio-guard`, run from the package of the main checkout without booting the worktree's application; it refuses the command when it fails, and takes the protected branches and the extra directories from the loop instead of the worktree's `.claude/settings.json` (an agent could widen them, and `/` let every path through). `agentio:guard` is removed.
- The guard splits command lines like a shell and closes the bypasses found in an audit: artisan options before the command (`php artisan -n tinker`), `agentio:run --dry-run --kill/--stop`, git aliases and `git -c`/`config`, combined short flags (`git push -fu`), `cd` before a relative path, `cp`/`sed -i`/`>` onto the agentio settings, `find -exec`, `php -i`, `/proc/$$/environ`, `.en?`; and it no longer refuses a plan or a commit message that merely mentions `.env`.
- `Bash(php -i)` is no longer allowed (it printed the token), and the `tinker` and `get-config` tools of Laravel Boost are denied.
- `agentio:run --dry-run` cannot be combined with `--stop` or `--kill`.
- Accepting an epic merges `refs/heads/<branch>`: a tag named like the branch no longer wins.
- An epic branch without commits of its own is no longer accepted as "already merged".
- Acceptance runs under a lock of the repository (shared by the dashboard and the loop) instead of the cache lock, and a merge that fails or times out is always aborted.
- The dashboard's git calls take no optional locks, so its polling no longer makes commits of agents and humans fail on `index.lock`.

### Removed

- `sync-stage`: Stage is the status field itself, nothing is derived from another field.


## [v0.1.0](https://github.com/obrazmisli/agentio/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
