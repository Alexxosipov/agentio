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
    | back into it through a GitHub pull request (gh). "production_branch" is
    | what production runs; a release reaches it through a pull request the
    | developer confirms. When one of them is null, the value agentio:install
    | recorded in .agentio.json is used (then "dev" and "main").
    | "worktrees_path" is where the git worktrees
    | of the epics are created; agentio:install asks for it and writes it to
    | .env (it has no default: the loop refuses to start without it).
    | "worktree_setup" is an optional shell command run last in every new epic
    | worktree with the epic id as $1 (seeders, extra services, ...).
    | "limit_retry" is how many seconds the loop pauses when a session runs
    | into the usage limit of Claude Code without saying when it resets.
    |
    */

    'base_branch' => env('AGENTIO_BASE_BRANCH'),

    'production_branch' => env('AGENTIO_PRODUCTION_BRANCH'),

    'max_parallel' => (int) env('AGENTIO_MAX_PARALLEL', 2),

    'max_parallel_tasks' => (int) env('AGENTIO_MAX_PARALLEL_TASKS', 2),

    'interval' => (int) env('AGENTIO_INTERVAL', 60),

    'limit_retry' => (int) env('AGENTIO_LIMIT_RETRY', 900),

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
    | Telegram Bot
    |--------------------------------------------------------------------------
    |
    | The developer's own bot (optional), a project manager in a chat: it sends
    | the questions of the agents ([AGENT:BLOCKED]) and short reports of the
    | finished work, and answers the developer's text and voice messages (an
    | answer to a question becomes a YouTrack comment and returns the issue to
    | work, a new idea becomes a YouTrack issue). Create the bot with
    | @BotFather and run php artisan agentio:setup-telegram <token>: it writes
    | the token and the paired chat to .env. agentio:run starts the process
    | that reads the updates (getUpdates) next to the loop. Every message goes
    | through the "queue" of the "queue_connection", worked by Laravel Horizon.
    | "transcription" turns voice messages into text: "openai" (any
    | OpenAI-compatible /audio/transcriptions endpoint at "url") or "whisper"
    | (the local whisper.cpp CLI with ffmpeg); null leaves voice unanswered.
    |
    */

    'telegram' => [
        'token' => env('AGENTIO_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('AGENTIO_TELEGRAM_CHAT_ID'),
        'api_url' => env('AGENTIO_TELEGRAM_API_URL', 'https://api.telegram.org'),
        'queue_connection' => env('AGENTIO_TELEGRAM_QUEUE_CONNECTION', 'redis'),
        'queue' => env('AGENTIO_TELEGRAM_QUEUE', 'default'),
        'watch_interval' => (int) env('AGENTIO_TELEGRAM_WATCH_INTERVAL', 60),
        'assistant_timeout' => (int) env('AGENTIO_TELEGRAM_ASSISTANT_TIMEOUT', 600),
        'transcription' => [
            'driver' => env('AGENTIO_TRANSCRIPTION_DRIVER'),
            'url' => env('AGENTIO_TRANSCRIPTION_URL', 'https://api.openai.com/v1'),
            'key' => env('AGENTIO_TRANSCRIPTION_KEY'),
            'model' => env('AGENTIO_TRANSCRIPTION_MODEL', 'whisper-1'),
            'whisper_binary' => env('AGENTIO_WHISPER_BIN', 'whisper-cli'),
            'whisper_model' => env('AGENTIO_WHISPER_MODEL'),
            'ffmpeg_binary' => env('AGENTIO_FFMPEG_BIN', 'ffmpeg'),
            'language' => env('AGENTIO_TRANSCRIPTION_LANGUAGE', 'ru'),
        ],
    ],

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
    | in Review from the page (merging its pull request into the base branch
    | on GitHub) and sending it back for rework; false makes the page
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
