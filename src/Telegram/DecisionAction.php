<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

/**
 * The YouTrack changes the assistant may decide on (see Decision and ActionRunner).
 */
enum DecisionAction: string
{
    /** The developer answered the questions of an issue: a comment, and with resume the issue goes back to work. */
    case Answer = 'answer';

    /** A remark for an issue: a comment, nothing else. */
    case Comment = 'comment';

    /** A new idea for the planning loop. */
    case Idea = 'idea';
}
