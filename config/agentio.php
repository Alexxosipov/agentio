<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | YouTrack
    |--------------------------------------------------------------------------
    |
    | YouTrack is the source of truth for the autonomous cycle: ideas, epics,
    | stories, tasks, agent comments and the knowledge base. The agents and the
    | loop work with it through its MCP server (<url>/mcp); the REST API is used
    | only where MCP has no tool: the project setup and the dashboard. The token
    | is only ever read from the environment (.env, written by agentio:install).
    | "project" is the project's short name; when it is not set, the project
    | agentio:install recorded in .agentio.json is used (then "TP").
    |
    */

    'youtrack' => [
        'url' => env('YOUTRACK_URL'),
        'token' => env('YOUTRACK_TOKEN'),
        'project' => env('AGENTIO_PROJECT'),
        'timeout' => (int) env('AGENTIO_YOUTRACK_TIMEOUT', 30),
        'retries' => (int) env('AGENTIO_YOUTRACK_RETRIES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Autonomous Loop
    |--------------------------------------------------------------------------
    |
    | Settings of the agent loop (php artisan agentio:run). "base_branch" is the
    | development branch: what the develop server runs; every epic is worked on
    | in a branch named after its issue id (TP-12), started from it and merged
    | back into it. "production_branch" is what production runs; humans merge
    | releases of the development branch into it. When one of them or
    | "merge_policy" (local-branch, pull-request or auto-merge) is null, the
    | value agentio:install recorded in .agentio.json is used (then "dev",
    | "main" and "local-branch"). "worktrees_path" is where the git worktrees
    | of the epics are created; agentio:install asks for it and writes it to
    | .env (it has no default: the loop refuses to start without it).
    | "worktree_setup" is an optional shell command run last in every new epic
    | worktree with the epic id as $1 (seeders, extra services, ...).
    |
    */

    'base_branch' => env('AGENTIO_BASE_BRANCH'),

    'production_branch' => env('AGENTIO_PRODUCTION_BRANCH'),

    'merge_policy' => env('AGENTIO_MERGE_POLICY'),

    'max_parallel' => (int) env('AGENTIO_MAX_PARALLEL', 2),

    'max_parallel_tasks' => (int) env('AGENTIO_MAX_PARALLEL_TASKS', 2),

    'interval' => (int) env('AGENTIO_INTERVAL', 300),

    'worktrees_path' => env('AGENTIO_WORKTREES_PATH'),

    'worktree_setup' => env('AGENTIO_WORKTREE_SETUP'),

    'claude_binary' => env('AGENTIO_CLAUDE_BIN', 'claude'),

    'claude_model' => env('AGENTIO_CLAUDE_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    |
    | Where the agent loop writes its files: loop.log, <ID>.log and <ID>.pid
    | for epic sessions, plan-<ID>.log for planning sessions, and the stop
    | flag. The loop writes local time; "timezone" is that time zone (null
    | detects the machine's time zone from $TZ, /etc/timezone or
    | /etc/localtime).
    |
    */

    'logs_path' => env('AGENTIO_LOGS_PATH', storage_path('logs/agents')),

    'timezone' => env('AGENTIO_TIMEZONE'),

    /*
    |--------------------------------------------------------------------------
    | User Interface
    |--------------------------------------------------------------------------
    |
    | The process dashboard served at /{path}. Access is granted by the
    | "viewAgentio" gate, the actions by the "manageAgentio" gate: always in
    | the local environment, otherwise to the listed emails. Define your own
    | gates, or override both with Agentio::auth(fn ($request) => ...).
    | The page polls its JSON endpoints every "poll" seconds; YouTrack
    | responses are cached for "cache" seconds (0 disables the cache; failures
    | are cached for at least 30 seconds). "actions" allows accepting an epic
    | in Review from the page (merging its branch into the base branch of
    | this checkout) and sending it back for rework; false makes the page
    | read-only.
    |
    */

    'ui' => [
        'enabled' => (bool) env('AGENTIO_UI_ENABLED', true),
        'path' => env('AGENTIO_UI_PATH', 'agentio'),
        'domain' => env('AGENTIO_UI_DOMAIN'),
        'middleware' => ['web'],
        'poll' => (int) env('AGENTIO_UI_POLL', 5),
        'cache' => (int) env('AGENTIO_UI_CACHE', 15),
        'actions' => (bool) env('AGENTIO_UI_ACTIONS', true),
        'allowed_emails' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('AGENTIO_ALLOWED_EMAILS', '')),
        ))),
    ],

];
