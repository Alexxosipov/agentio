# Release Notes

## [Unreleased](https://github.com/Alexxosipov/agentio/compare/v0.1.0...main)

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
