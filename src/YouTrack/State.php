<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack;

/**
 * The status of an issue: a value of the project's Stage field (the status field YouTrack gives new projects;
 * agentio:setup-youtrack adds the values the cycle needs to its bundle).
 */
enum State: string
{
    /** The custom field that holds the status. */
    public const string FIELD = 'Stage';

    case Backlog = 'Backlog';
    case Analysis = 'Analysis';
    case Ready = 'Ready';
    case InProgress = 'In Progress';
    case Review = 'Review';
    case Blocked = 'Blocked';

    /**
     * Not to be worked on until a human decides: an epic paused by the developer (its claim stays with its owner),
     * or an idea analysed and parked (the parked tag) instead of being planned into development.
     */
    case OnHold = 'On Hold';

    case Done = 'Done';
}
