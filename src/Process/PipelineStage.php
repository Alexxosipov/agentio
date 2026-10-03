<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Process;

/**
 * The stages an idea and its epics go through in the autonomous cycle, in order.
 */
enum PipelineStage: string
{
    case Idea = 'idea';
    case Analysis = 'analysis';
    case Architecture = 'architecture';
    case Decomposition = 'decomposition';
    case Development = 'development';
    case StoryReview = 'story_review';
    case Acceptance = 'acceptance';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Idea => 'Идея',
            self::Analysis => 'Требования и аналитика',
            self::Architecture => 'Архитектура',
            self::Decomposition => 'Декомпозиция',
            self::Development => 'Разработка',
            self::StoryReview => 'Ревью историй',
            self::Acceptance => 'Приёмка человеком',
            self::Done => 'Готово',
        };
    }

    /**
     * Position of the stage in the pipeline, starting at 0.
     */
    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /**
     * The earliest of the given stages, or null for an empty list.
     */
    public static function earliest(self ...$stages): ?self
    {
        $earliest = null;

        foreach ($stages as $stage) {
            if ($earliest === null || $stage->position() < $earliest->position()) {
                $earliest = $stage;
            }
        }

        return $earliest;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function toList(): array
    {
        return array_map(fn (self $stage): array => ['key' => $stage->value, 'label' => $stage->label()], self::cases());
    }
}
