<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack;

enum IssueType: string
{
    case Idea = 'Idea';
    case Epic = 'Epic';
    case Story = 'Story';
    case Task = 'Task';
}
