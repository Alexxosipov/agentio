# Release Notes

## [Unreleased](https://github.com/obrazmisli/agentio/compare/v0.1.0...1.x)

### Changed

- `agentio:install` adds only skills to the project (`.claude/skills/agentio-*`, packaged as Claude Code skills with the `agentio-` prefix; the subagents became the `agentio-develop-task` and `agentio-review-story` skills) plus `.agentio.json` and `.env` keys. Scripts, hooks, session settings, MCP configs and the manual stay in the package; nothing is merged into `.claude/settings.json`, `.mcp.json`, `CLAUDE.md`, `.gitignore` or `.env.example`.
- The agents work with YouTrack only through its MCP server. `scripts/yt.php` is replaced by `php artisan agentio:yt`, which also talks to the MCP server; the REST API is left to the project setup and the dashboard.
- The loop scripts run only through artisan: `agentio:run`, `agentio:worktree`, `agentio:commit`, `agentio:test`, `agentio:log` and the hidden `agentio:guard` hook.
- The status of an issue is the **Stage** field, the status field YouTrack gives new projects; `agentio:setup-youtrack` adds the cycle's values to its bundle. A `State` field («Состояние») is no longer used: the setup reports it and leaves it as is.
- `agentio:install` checks the token through the MCP server, adds the `youtrack` MCP server to Claude Code with `claude mcp add` when it has none, and asks for the directory of the epic worktrees (`AGENTIO_WORKTREES_PATH`, required; there is no default any more).
- The merge policy is recorded in `.agentio.json` instead of a `MERGE_POLICY:` line of `CLAUDE.md`; the stop flag lives in the logs directory.

### Added

- Epic acceptance in the dashboard: the epic page shows the commits, changed files and per-file diffs of the epic branch, the merge checks, the final `[AGENT:DONE]` and the story verdicts; an epic in `Review` can be accepted (merge into the base branch, worktree and branch removal, stories and epic to `Done`) or sent back for rework (a TASK with the remark, the story and the epic back to `Ready`). `AGENTIO_UI_ACTIONS=false` makes the dashboard read-only.
- `agentio:setup-youtrack` configures the YouTrack project on its own and can run any number of times without creating anything twice. It uses the project's Stage and Type fields, found by name or localized name.

### Removed

- `sync-stage`: Stage is the status field itself, nothing is derived from another field.


## [v0.1.0](https://github.com/obrazmisli/agentio/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
