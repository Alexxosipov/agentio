<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\State;

/**
 * The Stage an issue in Blocked returns to once the developer answered: the one its [AGENT:BLOCKED] names
 * («…, затем Stage → Backlog», «верните Stage в **Ready**»), else Backlog for an idea and Ready for the rest.
 */
final class ReturnStage
{
    /** The stages an answered issue may return to. */
    public const array ALLOWED = [State::Backlog, State::Analysis, State::Ready, State::InProgress];

    /**
     * The Stage the text of an [AGENT:BLOCKED] comment names, or null.
     */
    public static function named(string $text): ?string
    {
        $stages = implode('|', array_map(fn (State $state): string => preg_quote($state->value, '/'), self::ALLOWED));

        if (preg_match('/Stage\s*(?:→|->|=>|—>|в|на)\s*\**\s*('.$stages.')\b/iu', $text, $match) !== 1) {
            return null;
        }

        foreach (self::ALLOWED as $state) {
            if (strcasecmp($state->value, $match[1]) === 0) {
                return $state->value;
            }
        }

        return null;
    }

    public static function of(Issue $issue, ?string $blockedText): string
    {
        return ($blockedText === null ? null : self::named($blockedText))
            ?? ($issue->isIdea() ? State::Backlog->value : State::Ready->value);
    }
}
