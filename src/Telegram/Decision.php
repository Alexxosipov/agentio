<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use JsonException;

/**
 * What the assistant decided to do with a message of the developer: the reply, and the changes agentio makes for
 * it (the assistant session itself only reads):
 *
 * - answer: {issue, comment, resume} — the developer answered the questions of an issue: the comment is posted,
 *   and with resume the issue goes back from Blocked to work;
 * - comment: {issue, comment} — a remark for an issue, nothing else changes;
 * - idea: {summary, description, park} — a new idea for the planning loop; with park, an idea for later (only the
 *   system analysis, nothing goes to development until the developer takes it);
 * - promote: {issue} — take a parked idea into development;
 * - pause: {issue, now, comment} — pause an epic: after its current wave of tasks, or with now right away; the
 *   comment is the reason;
 * - resume: {issue} — lift the pause of an epic;
 * - merge: {issue} — merge the pull request of the epic into the development branch and close the epic;
 * - release: {confirm} — open the release pull request and ask the developer to confirm it; with confirm (the
 *   developer said yes to that question), merge it.
 */
final readonly class Decision
{
    /**
     * @param  list<array{type: DecisionAction, issue: string|null, comment: string, resume: bool, summary: string, description: string, confirm: bool, now: bool, park: bool}>  $actions
     */
    public function __construct(
        public string $reply,
        public array $actions = [],
    ) {}

    /**
     * The decision in the final text of the assistant: a JSON object {"reply": "…", "actions": [...]}, bare or in a
     * ```json block. A text without one is the reply itself, without actions.
     */
    public static function fromText(string $text): self
    {
        $data = self::json($text);

        if ($data === null || ! is_string($data['reply'] ?? null)) {
            return new self(trim($text));
        }

        $actions = [];

        foreach (is_array($data['actions'] ?? null) ? $data['actions'] : [] as $action) {
            if (! is_array($action)) {
                continue;
            }

            $type = is_string($action['type'] ?? null) ? DecisionAction::tryFrom($action['type']) : null;

            if ($type === null) {
                continue;
            }

            $issue = is_string($action['issue'] ?? null) && preg_match('/^[A-Za-z][A-Za-z0-9_]*-\d+$/', trim($action['issue'])) === 1 ? strtoupper(trim($action['issue'])) : null;

            $actions[] = [
                'type' => $type,
                'issue' => $issue,
                'comment' => is_string($action['comment'] ?? null) ? trim($action['comment']) : '',
                'resume' => ($action['resume'] ?? false) === true,
                'summary' => is_string($action['summary'] ?? null) ? trim($action['summary']) : '',
                'description' => is_string($action['description'] ?? null) ? trim($action['description']) : '',
                'confirm' => ($action['confirm'] ?? false) === true,
                'now' => ($action['now'] ?? false) === true,
                'park' => ($action['park'] ?? false) === true,
            ];
        }

        return new self(trim($data['reply']), $actions);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function json(string $text): ?array
    {
        $candidates = [trim($text)];

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $match) === 1) {
            $candidates[] = $match[1];
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $candidates[] = substr($text, $start, $end - $start + 1);
        }

        foreach ($candidates as $candidate) {
            try {
                $data = json_decode($candidate, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }
}
