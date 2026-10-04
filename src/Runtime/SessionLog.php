<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * A Claude Code session log written by the agent loop: "===== <time> <command> =====" header
 * lines followed by the `--output-format stream-json --verbose` events of the session, one JSON per line.
 *
 * Logs grow to gigabytes, so they are never loaded whole: read() pages forward from a byte offset
 * (for polling), tail() and lastResult() read backwards from the end.
 */
final readonly class SessionLog
{
    public const int DEFAULT_MAX_BYTES = 8 * 1024 * 1024;

    private const int TEXT_LIMIT = 2000;

    private const int SUMMARY_LIMIT = 300;

    /**
     * Input keys that best describe a tool call, in order of preference.
     */
    private const array SUMMARY_KEYS = ['description', 'command', 'file_path', 'notebook_path', 'path', 'pattern', 'query', 'skill', 'url', 'summary', 'text', 'prompt'];

    /**
     * Input keys that identify the YouTrack entity an MCP tool works on.
     */
    private const array ENTITY_KEYS = ['issueId', 'targetIssueId', 'articleId'];

    /**
     * @param  string|null  $timezone  Timezone of the loop's header lines (local time of the machine)
     */
    public function __construct(private string $path, private ?string $timezone = null) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function size(): int
    {
        clearstatcache(true, $this->path);

        return $this->exists() ? (int) filesize($this->path) : 0;
    }

    public function modifiedAt(): ?CarbonImmutable
    {
        return $this->exists() ? CarbonImmutable::createFromTimestampUTC((int) filemtime($this->path)) : null;
    }

    /**
     * Events of the complete lines after $offset, at most $limit events (whole lines are never split)
     * and at most $maxBytes read. A line still being written is left for the next call.
     * An offset past the end of the file (the log was truncated) restarts from the beginning.
     */
    public function read(int $offset = 0, int $limit = 200, int $maxBytes = self::DEFAULT_MAX_BYTES): SessionLogPage
    {
        $size = $this->size();
        $offset = $offset > $size || $offset < 0 ? 0 : $offset;
        $handle = $size > 0 ? @fopen($this->path, 'rb') : false;

        if ($handle === false) {
            return new SessionLogPage([], $offset, false);
        }

        $events = [];
        $next = $offset;

        try {
            fseek($handle, $offset);

            while (count($events) < $limit && $next - $offset < $maxBytes && ($line = fgets($handle)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    break;
                }

                array_push($events, ...self::parseLine($line, $next, $this->timezone));
                $next += strlen($line);
            }
        } finally {
            fclose($handle);
        }

        return new SessionLogPage(self::fillTimes($events), $next, $this->hasCompleteLineAfter($next));
    }

    /**
     * The last $limit events, oldest first, scanning at most $maxBytes from the end.
     *
     * @return list<SessionEvent>
     */
    public function tail(int $limit = 50, int $maxBytes = self::DEFAULT_MAX_BYTES): array
    {
        if ($limit <= 0) {
            return [];
        }

        $groups = [];
        $count = 0;

        foreach (ReverseLineReader::lines($this->path, $maxBytes) as $offset => $line) {
            $events = self::parseLine($line, $offset, $this->timezone);

            if ($events !== []) {
                $groups[] = $events;
                $count += count($events);
            }

            if ($count >= $limit) {
                break;
            }
        }

        $events = array_merge(...array_reverse($groups));

        return self::fillTimes(array_slice($events, -$limit));
    }

    /**
     * The latest Result event (cost, duration, final text), scanning at most $maxBytes from the end.
     */
    public function lastResult(int $maxBytes = self::DEFAULT_MAX_BYTES): ?SessionEvent
    {
        foreach (ReverseLineReader::lines($this->path, $maxBytes) as $offset => $line) {
            if (! str_contains($line, '"type":"result"')) {
                continue;
            }

            foreach (self::parseLine($line, $offset, $this->timezone) as $event) {
                if ($event->type === SessionEventType::Result) {
                    return $event;
                }
            }
        }

        return null;
    }

    /**
     * The events of one log line (an assistant message may hold several text / tool blocks).
     *
     * @return list<SessionEvent>
     */
    public static function parseLine(string $line, int $offset = 0, ?string $timezone = null): array
    {
        $line = trim($line);

        if ($line === '') {
            return [];
        }

        if (str_starts_with($line, '=====')) {
            return [self::header($line, $offset, $timezone)];
        }

        $event = str_starts_with($line, '{') ? json_decode($line, true) : null;

        if (! is_array($event)) {
            return str_starts_with($line, '{') ? [] : [new SessionEvent(SessionEventType::Text, $offset, self::limit($line, self::TEXT_LIMIT), isError: true)];
        }

        $context = [
            'offset' => $offset,
            'time' => self::time($event['timestamp'] ?? null),
            'inSubagent' => is_string($event['parent_tool_use_id'] ?? null) && $event['parent_tool_use_id'] !== '',
            'sessionId' => self::string($event['session_id'] ?? null),
        ];

        return match ($event['type'] ?? null) {
            'system' => self::system($event, $context),
            'assistant' => self::assistant($event, $context),
            'user' => self::user($event, $context),
            'result' => [self::result($event, $context)],
            default => [],
        };
    }

    private static function header(string $line, int $offset, ?string $timezone): SessionEvent
    {
        $text = trim($line, "= \t");
        $time = null;

        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(.*)$/', $text, $match) === 1) {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $match[1], $timezone);
            $time = $parsed instanceof CarbonImmutable ? $parsed : null;
            $text = $match[2];
        }

        return new SessionEvent(SessionEventType::SessionStart, $offset, $text, $time);
    }

    /**
     * @param  array<array-key, mixed>  $event
     * @param  array{offset: int, time: CarbonImmutable|null, inSubagent: bool, sessionId: string|null}  $context
     * @return list<SessionEvent>
     */
    private static function system(array $event, array $context): array
    {
        return match ($event['subtype'] ?? null) {
            'init' => [new SessionEvent(
                SessionEventType::Init, $context['offset'], self::string($event['cwd'] ?? null) ?? '', $context['time'],
                sessionId: $context['sessionId'], model: self::string($event['model'] ?? null),
            )],
            'permission_denied' => [new SessionEvent(
                SessionEventType::PermissionDenied, $context['offset'], self::firstSentence(self::string($event['message'] ?? null) ?? ''), $context['time'],
                tool: self::string($event['tool_name'] ?? null), inSubagent: is_string($event['agent_id'] ?? null), sessionId: $context['sessionId'], isError: true,
            )],
            'task_notification' => [new SessionEvent(
                SessionEventType::TaskFinished, $context['offset'], self::limit(self::string($event['summary'] ?? null) ?? '', self::SUMMARY_LIMIT), $context['time'],
                sessionId: $context['sessionId'], status: self::string($event['status'] ?? null), isError: ($event['status'] ?? null) === 'failed',
            )],
            default => [],
        };
    }

    /**
     * @param  array<array-key, mixed>  $event
     * @param  array{offset: int, time: CarbonImmutable|null, inSubagent: bool, sessionId: string|null}  $context
     * @return list<SessionEvent>
     */
    private static function assistant(array $event, array $context): array
    {
        $subagent = $context['inSubagent'] ? (self::string($event['task_description'] ?? null) ?? self::string($event['subagent_type'] ?? null)) : null;
        $events = [];

        foreach (self::blocks($event) as $block) {
            $type = $block['type'] ?? null;

            if ($type === 'text' && trim(self::string($block['text'] ?? null) ?? '') !== '') {
                $events[] = new SessionEvent(
                    SessionEventType::Text, $context['offset'], self::limit(trim((string) $block['text']), self::TEXT_LIMIT), $context['time'],
                    subagent: $subagent, inSubagent: $context['inSubagent'], sessionId: $context['sessionId'],
                );
            } elseif ($type === 'tool_use') {
                $events[] = self::toolUse($block, $context, $subagent);
            }
        }

        return $events;
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @param  array{offset: int, time: CarbonImmutable|null, inSubagent: bool, sessionId: string|null}  $context
     */
    private static function toolUse(array $block, array $context, ?string $subagent): SessionEvent
    {
        $tool = self::string($block['name'] ?? null) ?? '?';
        $input = is_array($block['input'] ?? null) ? $block['input'] : [];

        if (in_array($tool, ['Agent', 'Task'], true)) {
            return new SessionEvent(
                SessionEventType::Subagent, $context['offset'],
                self::limit(self::string($input['description'] ?? null) ?? self::string($input['prompt'] ?? null) ?? '', self::SUMMARY_LIMIT),
                $context['time'], tool: $tool, subagent: self::string($input['subagent_type'] ?? null) ?? 'general-purpose',
                inSubagent: $context['inSubagent'], sessionId: $context['sessionId'],
            );
        }

        [$text, $detail] = self::summarize($input);

        return new SessionEvent(
            SessionEventType::ToolUse, $context['offset'], $text, $context['time'], detail: $detail, tool: $tool,
            subagent: $subagent, inSubagent: $context['inSubagent'], sessionId: $context['sessionId'],
        );
    }

    /**
     * @param  array<array-key, mixed>  $event
     * @param  array{offset: int, time: CarbonImmutable|null, inSubagent: bool, sessionId: string|null}  $context
     * @return list<SessionEvent>
     */
    private static function user(array $event, array $context): array
    {
        $subagent = $context['inSubagent'] ? (self::string($event['task_description'] ?? null) ?? self::string($event['subagent_type'] ?? null)) : null;
        $events = [];

        foreach (self::blocks($event) as $block) {
            if (($block['type'] ?? null) === 'tool_result' && ($block['is_error'] ?? false) === true) {
                $content = $block['content'] ?? '';
                $text = is_string($content) ? $content : (string) json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $events[] = new SessionEvent(
                    SessionEventType::ToolError, $context['offset'], self::limit(self::squish($text), self::SUMMARY_LIMIT), $context['time'],
                    subagent: $subagent, inSubagent: $context['inSubagent'], sessionId: $context['sessionId'], isError: true,
                );
            }
        }

        return $events;
    }

    /**
     * @param  array<array-key, mixed>  $event
     * @param  array{offset: int, time: CarbonImmutable|null, inSubagent: bool, sessionId: string|null}  $context
     */
    private static function result(array $event, array $context): SessionEvent
    {
        return new SessionEvent(
            SessionEventType::Result, $context['offset'], self::limit(trim(self::string($event['result'] ?? null) ?? ''), self::TEXT_LIMIT), $context['time'],
            sessionId: $context['sessionId'],
            status: self::string($event['subtype'] ?? null),
            costUsd: is_int($event['total_cost_usd'] ?? null) || is_float($event['total_cost_usd'] ?? null) ? (float) $event['total_cost_usd'] : null,
            durationMs: is_int($event['duration_ms'] ?? null) ? $event['duration_ms'] : null,
            turns: is_int($event['num_turns'] ?? null) ? $event['num_turns'] : null,
            isError: ($event['is_error'] ?? false) === true,
        );
    }

    /**
     * A one-line summary of a tool input and, when the summary is a description, the command or path.
     *
     * @param  array<array-key, mixed>  $input
     * @return array{0: string, 1: string|null}
     */
    private static function summarize(array $input): array
    {
        $entity = null;

        foreach (self::ENTITY_KEYS as $key) {
            if (is_string($input[$key] ?? null) && $input[$key] !== '') {
                $entity = $input[$key];

                break;
            }
        }

        $summary = null;

        foreach (self::SUMMARY_KEYS as $key) {
            if (is_string($input[$key] ?? null) && trim($input[$key]) !== '') {
                $summary = $input[$key];

                break;
            }
        }

        if ($summary === null) {
            $rest = array_diff_key($input, array_flip(self::ENTITY_KEYS));
            $summary = $rest === [] ? '' : (string) json_encode($rest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $text = self::limit(self::squish(trim(($entity ?? '').' '.$summary)), self::SUMMARY_LIMIT);
        $detail = is_string($input['description'] ?? null)
            ? (self::string($input['command'] ?? null) ?? self::string($input['file_path'] ?? null))
            : null;

        return [$text, $detail === null ? null : self::limit(self::squish($detail), self::SUMMARY_LIMIT)];
    }

    /**
     * @param  array<array-key, mixed>  $event
     * @return list<array<array-key, mixed>>
     */
    private static function blocks(array $event): array
    {
        $message = is_array($event['message'] ?? null) ? $event['message'] : [];
        $content = $message['content'] ?? null;

        return is_array($content) ? array_values(array_filter($content, is_array(...))) : [];
    }

    /**
     * Give events without a timestamp (system and result events) the time of the event before them.
     *
     * @param  list<SessionEvent>  $events
     * @return list<SessionEvent>
     */
    private static function fillTimes(array $events): array
    {
        $last = null;

        foreach ($events as $index => $event) {
            if ($event->time === null && $last !== null) {
                $events[$index] = $event->withTime($last);
            }

            $last = $events[$index]->time ?? $last;
        }

        return $events;
    }

    private function hasCompleteLineAfter(int $offset): bool
    {
        $size = $this->size();

        if ($offset >= $size) {
            return false;
        }

        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            return false;
        }

        fseek($handle, $offset);
        $line = fgets($handle);
        fclose($handle);

        return is_string($line) && str_ends_with($line, "\n");
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function firstSentence(string $text): string
    {
        $sentence = preg_split('/(?<=\.)\s/', $text, 2)[0] ?? $text;

        return self::limit(rtrim($sentence, '.'), self::SUMMARY_LIMIT);
    }

    private static function squish(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function limit(string $text, int $limit): string
    {
        return Str::limit($text, $limit, '…');
    }
}
