<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack;

enum State: string
{
    case Backlog = 'Backlog';
    case Analysis = 'Analysis';
    case Ready = 'Ready';
    case InProgress = 'In Progress';
    case Review = 'Review';
    case Blocked = 'Blocked';
    case Done = 'Done';
}
