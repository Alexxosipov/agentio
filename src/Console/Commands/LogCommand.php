<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Console\Commands;

use Illuminate\Console\Command;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Runtime\ReverseLineReader;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Pretty-prints the log of an agent session (Claude Code stream-json) written by the agent loop:
 * <logs>/<EPIC>.log for /agentio-work-epic, <logs>/plan-<IDEA>.log for /agentio-plan.
 */
#[AsCommand(name: 'agentio:log')]
final class LogCommand extends Command
{
    /** Bytes read from the end of a log for its last lines at most. */
    public const int MAX_BYTES = 16 * 1024 * 1024;

    /**
     * @var string
     */
    protected $signature = 'agentio:log
        {session : EPIC-ID, plan-IDEA-ID or the path of a log file}
        {--lines=40 : Lines to print}
        {--follow : Keep printing new events}';

    /**
     * @var string
     */
    protected $description = 'Print the log of an agent session of the loop in a readable form';

    public function handle(LoopState $loop): int
    {
        $session = (string) $this->argument('session');
        $path = is_file($session) ? $session : rtrim($loop->logsPath(), '/').'/'.$session.'.log';

        if (! is_file($path)) {
            $this->components->error("Log not found: {$path}");

            return self::FAILURE;
        }

        // Read backwards from the end: logs of long sessions grow to gigabytes.
        $limit = max(1, (int) $this->option('lines'));
        $rendered = [];

        foreach (ReverseLineReader::lines($path, self::MAX_BYTES) as $line) {
            array_unshift($rendered, ...self::render($line));

            if (count($rendered) >= $limit) {
                break;
            }
        }

        foreach (array_slice($rendered, -$limit) as $line) {
            $this->line($line, null);
        }

        return $this->option('follow') ? $this->follow($path) : self::SUCCESS;
    }

    /**
     * Print the events written after the current end of the log, whole lines only; start over when the log
     * was truncated.
     */
    private function follow(string $path): int
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return self::FAILURE;
        }

        fseek($handle, 0, SEEK_END);
        $pending = '';

        while (true) { // @phpstan-ignore while.alwaysTrue
            clearstatcache(true, $path);

            if ((int) filesize($path) < (int) ftell($handle)) {
                fseek($handle, 0);
                $pending = '';
            }

            $chunk = (string) fread($handle, 65536);

            if ($chunk === '') {
                usleep(500_000);

                continue;
            }

            $lines = explode("\n", $pending.$chunk);
            $pending = (string) array_pop($lines);

            foreach ($lines as $line) {
                foreach (self::render(rtrim($line)) as $output) {
                    $this->line($output, null);
                }
            }
        }
    }

    /**
     * The readable lines of one log line: the session start, assistant messages and tool calls (subagent ones
     * indented), failed tool results and the final result; other text lines as they are.
     *
     * @return list<string>
     */
    public static function render(string $line): array
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
        $message = is_array($event['message'] ?? null) ? $event['message'] : [];
        $content = is_array($message['content'] ?? null) ? $message['content'] : [];

        switch ($event['type'] ?? '') {
            case 'system':
                if (($event['subtype'] ?? '') === 'init') {
                    $output[] = '== session '.self::text($event['session_id'] ?? '?').' model='.self::text($event['model'] ?? '?').' cwd='.self::text($event['cwd'] ?? '?');
                }

                break;
            case 'assistant':
                foreach ($content as $block) {
                    if (! is_array($block)) {
                        continue;
                    }

                    if (($block['type'] ?? '') === 'text') {
                        $output[] = $prefix.'💬 '.self::shorten(self::text($block['text'] ?? ''), 400);
                    } elseif (($block['type'] ?? '') === 'tool_use') {
                        $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                        $summary = $input['command'] ?? $input['description'] ?? $input['file_path'] ?? $input['issueId'] ?? json_encode($input, JSON_UNESCAPED_UNICODE);
                        $output[] = $prefix.'🔧 '.self::text($block['name'] ?? '?').': '.self::shorten(self::text($summary));
                    }
                }

                break;
            case 'user':
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'tool_result' && ($block['is_error'] ?? false) === true) {
                        $result = $block['content'] ?? '';
                        $output[] = $prefix.'⚠️  '.self::shorten(is_array($result) ? (string) json_encode($result, JSON_UNESCAPED_UNICODE) : self::text($result));
                    }
                }

                break;
            case 'result':
                $output[] = sprintf(
                    '== result: %s turns=%s cost=$%s duration=%smin',
                    self::text($event['subtype'] ?? '?'),
                    self::text($event['num_turns'] ?? '?'),
                    round(is_numeric($event['total_cost_usd'] ?? null) ? (float) $event['total_cost_usd'] : 0.0, 2),
                    round((is_numeric($event['duration_ms'] ?? null) ? (int) $event['duration_ms'] : 0) / 60000, 1),
                );
                $output[] = self::shorten(self::text($event['result'] ?? ''), 2000);

                break;
        }

        return $output;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function shorten(string $text, int $limit = 220): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }
}
