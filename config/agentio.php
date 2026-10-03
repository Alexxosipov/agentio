<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | YouTrack
    |--------------------------------------------------------------------------
    |
    | YouTrack is the source of truth for the autonomous cycle: ideas, epics,
    | stories, tasks, agent comments and the knowledge base. The token is only
    | ever read from the environment. "project" is the project's short name.
    |
    */

    'youtrack' => [
        'url' => env('YOUTRACK_URL'),
        'token' => env('YOUTRACK_TOKEN'),
        'project' => env('AGENTIO_PROJECT', 'TP'),
        'timeout' => (int) env('AGENTIO_YOUTRACK_TIMEOUT', 30),
        'retries' => (int) env('AGENTIO_YOUTRACK_RETRIES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Autonomous Loop
    |--------------------------------------------------------------------------
    |
    | Settings passed to scripts/agent-loop.sh. When "merge_policy" is null the
    | `MERGE_POLICY:` line of the project's CLAUDE.md is used, exactly like the
    | loop script does (local-branch, pull-request or auto-merge).
    |
    */

    'base_branch' => env('AGENTIO_BASE_BRANCH', 'main'),

    'merge_policy' => env('AGENTIO_MERGE_POLICY'),

    'max_parallel' => (int) env('AGENTIO_MAX_PARALLEL', 2),

    'max_parallel_tasks' => (int) env('AGENTIO_MAX_PARALLEL_TASKS', 2),

    'interval' => (int) env('AGENTIO_INTERVAL', 300),

    'worktrees_path' => env('AGENTIO_WORKTREES_PATH', base_path('../worktrees')),

    'claude_binary' => env('AGENTIO_CLAUDE_BIN', 'claude'),

    /*
    |--------------------------------------------------------------------------
    | Tests
    |--------------------------------------------------------------------------
    |
    | Commands of scripts/run-tests.sh, which the agents use for every test
    | run: "command" gets the script arguments (e.g. --filter=Profile), and
    | "full_command" is the full quality gate (RUN_TESTS_FULL=1). Null keeps
    | the script defaults: `php artisan test --compact`, and `composer test`
    | when composer.json has a "test" script.
    |
    */

    'tests' => [
        'command' => env('AGENTIO_TEST_COMMAND'),
        'full_command' => env('AGENTIO_FULL_TEST_COMMAND'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    |
    | Where scripts/agent-loop.sh writes its files: loop.log, <ID>.log and
    | <ID>.pid for epic sessions, plan-<ID>.log for planning sessions. The
    | loop writes local time; "timezone" is that time zone (null detects
    | the machine's time zone from $TZ, /etc/timezone or /etc/localtime).
    |
    */

    'logs_path' => env('AGENTIO_LOGS_PATH', storage_path('logs/agents')),

    'timezone' => env('AGENTIO_TIMEZONE'),

    /*
    |--------------------------------------------------------------------------
    | Stage Map
    |--------------------------------------------------------------------------
    |
    | The Stage field feeds the YouTrack Kanban board and is derived from State.
    |
    */

    'stage_map' => [
        'Backlog' => 'Backlog',
        'Analysis' => 'Backlog',
        'Ready' => 'Backlog',
        'Blocked' => 'Backlog',
        'In Progress' => 'Develop',
        'Review' => 'Review',
        'Done' => 'Done',
    ],

    /*
    |--------------------------------------------------------------------------
    | User Interface
    |--------------------------------------------------------------------------
    |
    | The process dashboard served at /{path}. Access is granted by the
    | "viewAgentio" gate: always in the local environment, otherwise to the
    | listed emails. Override it with Agentio::auth(fn ($request) => ...).
    | The page polls its JSON endpoints every "poll" seconds; YouTrack
    | responses are cached for "cache" seconds (0 disables the cache).
    |
    */

    'ui' => [
        'enabled' => (bool) env('AGENTIO_UI_ENABLED', true),
        'path' => env('AGENTIO_UI_PATH', 'agentio'),
        'domain' => env('AGENTIO_UI_DOMAIN'),
        'middleware' => ['web'],
        'poll' => (int) env('AGENTIO_UI_POLL', 5),
        'cache' => (int) env('AGENTIO_UI_CACHE', 5),
        'allowed_emails' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AGENTIO_ALLOWED_EMAILS', '')),
        ))),
    ],

];
