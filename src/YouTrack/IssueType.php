<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\YouTrack;

/**
 * The levels of the hierarchy (the Type field): IDEA, then EPIC -> STORY -> TASK.
 */
enum IssueType: string
{
    /** The custom field that holds the type. */
    public const string FIELD = 'Type';

    case Idea = 'Idea';
    case Epic = 'Epic';
    case Story = 'Story';
    case Task = 'Task';

    /**
     * The prefix of the summary, e.g. "[EPIC]".
     */
    public function prefix(): string
    {
        return '['.strtoupper($this->value).']';
    }

    /**
     * The type the parent (subtask of) must have: a story belongs to an epic, a task to a story.
     */
    public function parentType(): ?self
    {
        return match ($this) {
            self::Story => self::Epic,
            self::Task => self::Story,
            default => null,
        };
    }
}
