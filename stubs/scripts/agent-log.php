<?php

declare(strict_types=1);

/*
 * Pretty-prints an agent session log (Claude Code stream-json) written by scripts/agent-loop.sh.
 *
 * Usage: php scripts/agent-log.php <EPIC-ID|plan-IDEA-ID|path> [--lines=40] [--follow]
 */

$arguments = array_slice($argv, 1);
$target = $arguments[0] ?? null;

if ($target === null) {
    fwrite(STDERR, 'Usage: php scripts/agent-log.php <EPIC-ID|plan-IDEA-ID|path> [--lines=40] [--follow]'.PHP_EOL);

    exit(64);
}

/**
 * The logs directory: $AGENT_LOG_DIR (set by the loop), $AGENTIO_LOGS_PATH, the AGENTIO_LOGS_PATH line of the
 * project's .env, or storage/logs/agents.
 */
function logsDirectory(): string
{
    foreach (['AGENT_LOG_DIR', 'AGENTIO_LOGS_PATH'] as $name) {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    $dotenv = dirname(__DIR__).'/.env';

    foreach (is_file($dotenv) ? (file($dotenv, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
        if (preg_match('/^\s*AGENTIO_LOGS_PATH\s*=\s*(["\']?)(.+?)\1\s*(?:#.*)?$/', $line, $match) === 1) {
            return $match[2];
        }
    }

    return dirname(__DIR__).'/storage/logs/agents';
}

$path = is_file($target) ? $target : logsDirectory().'/'.$target.'.log';
$lines = 40;
$follow = in_array('--follow', $arguments, true);

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--lines=')) {
        $lines = max(1, (int) mb_substr($argument, 8));
    }
}

if (! is_file($path)) {
    fwrite(STDERR, "Log not found: {$path}".PHP_EOL);

    exit(1);
}

function shorten(string $text, int $limit = 220): string
{
    $text = trim((string) preg_replace('/\s+/', ' ', $text));

    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
}

/**
 * @return list<string>
 */
function render(string $line): array
{
    if (! str_starts_with($line, '{')) {
        return trim($line) === '' ? [] : [$line];
    }

    $event = json_decode($line, true);

    if (! is_array($event)) {
        return [];
    }

    $prefix = isset($event['parent_tool_use_id']) ? '  ↳ ' : '';
    $output = [];

    switch ($event['type'] ?? '') {
        case 'system':
            if (($event['subtype'] ?? '') === 'init') {
                $output[] = '== session '.($event['session_id'] ?? '?').' model='.($event['model'] ?? '?').' cwd='.($event['cwd'] ?? '?');
            }

            break;
        case 'assistant':
            foreach ($event['message']['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $output[] = $prefix.'💬 '.shorten((string) $block['text'], 400);
                } elseif (($block['type'] ?? '') === 'tool_use') {
                    $input = $block['input'] ?? [];
                    $summary = $input['command'] ?? $input['description'] ?? $input['file_path'] ?? $input['issueId'] ?? json_encode($input, JSON_UNESCAPED_UNICODE);
                    $output[] = $prefix.'🔧 '.$block['name'].': '.shorten((string) $summary);
                }
            }

            break;
        case 'user':
            foreach ($event['message']['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'tool_result' && ($block['is_error'] ?? false)) {
                    $content = is_array($block['content']) ? json_encode($block['content'], JSON_UNESCAPED_UNICODE) : (string) $block['content'];
                    $output[] = $prefix.'⚠️  '.shorten((string) $content);
                }
            }

            break;
        case 'result':
            $output[] = '== result: '.($event['subtype'] ?? '?').' turns='.($event['num_turns'] ?? '?').' cost=$'.round((float) ($event['total_cost_usd'] ?? 0), 2).' duration='.round(((int) ($event['duration_ms'] ?? 0)) / 60000, 1).'min';
            $output[] = shorten((string) ($event['result'] ?? ''), 2000);

            break;
    }

    return $output;
}

$rendered = [];

foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    array_push($rendered, ...render($line));
}

echo implode(PHP_EOL, array_slice($rendered, -$lines)).PHP_EOL;

if (! $follow) {
    exit(0);
}

$handle = fopen($path, 'r');
fseek($handle, 0, SEEK_END);

while (true) {
    $line = fgets($handle);

    if ($line === false) {
        usleep(500_000);
        fseek($handle, 0, SEEK_CUR);

        continue;
    }

    foreach (render(rtrim($line)) as $output) {
        echo $output.PHP_EOL;
    }
}
