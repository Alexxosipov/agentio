<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack;

/**
 * Link directions as YouTrack names them, seen from the issue that holds the link.
 */
enum Relation: string
{
    case DependsOn = 'depends on';
    case RequiredFor = 'is required for';
    case SubtaskOf = 'subtask of';
    case ParentFor = 'parent for';
    case RelatesTo = 'relates to';
}
