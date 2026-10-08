<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Telegram;

/**
 * The changes the assistant may decide on (see Decision and ActionRunner): YouTrack comments and ideas, the pause of
 * an epic, and the merges of pull requests the developer asks for (never a YouTrack issue).
 */
enum DecisionAction: string
{
    /** The developer answered the questions of an issue: a comment, and with resume the issue goes back to work. */
    case Answer = 'answer';

    /** A remark for an issue: a comment, nothing else. */
    case Comment = 'comment';

    /** A new idea for the planning loop; with park, an idea for later: analysed, not planned into development. */
    case Idea = 'idea';

    /** The developer takes a parked idea into development: the tag goes, the idea goes back to planning. */
    case Promote = 'promote';

    /** The developer pauses an epic (EpicPause): after its current wave of tasks, or with now right away. */
    case Pause = 'pause';

    /** The developer lifts the pause of an epic. */
    case Resume = 'resume';

    /** The developer asked to merge an epic: its pull request into the development branch (EpicAcceptance). */
    case Merge = 'merge';

    /**
     * The developer asked for a release: the pull request of the development branch into the production branch is
     * opened and the developer asked to confirm; with confirm, after that question, it is merged (Release).
     */
    case Release = 'release';
}
