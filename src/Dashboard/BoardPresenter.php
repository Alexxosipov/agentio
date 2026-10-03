<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Dashboard;

use Obrazmisli\Agentio\YouTrack\Issue;
use Obrazmisli\Agentio\YouTrack\IssueType;
use Obrazmisli\Agentio\YouTrack\State;
use Obrazmisli\Agentio\YouTrack\YouTrackException;

/**
 * The Kanban board: every issue of the project in a column per State.
 */
final readonly class BoardPresenter
{
    public function __construct(private YouTrackSource $source) {}

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $board = $this->source->attempt($this->board(...), ['columns' => $this->columns([]), 'epics' => []]);

        return [...$board, 'types' => array_map(fn (IssueType $type): string => $type->value, IssueType::cases()), 'youtrack' => $this->source->health()];
    }

    /**
     * @return array{columns: list<array<string, mixed>>, epics: list<array<string, mixed>>}
     *
     * @throws YouTrackException
     */
    private function board(): array
    {
        $graph = $this->source->graph();
        $cards = [];

        foreach ($graph->issues() as $issue) {
            $cards[] = [
                ...$this->source->card($issue),
                ...$this->source->claim($issue),
                'epicId' => $graph->epicOf($issue->id),
                'parentId' => $issue->parentId(),
                'unmetDependencies' => $issue->hasState(State::Done) ? [] : $graph->unmetDependencies($issue->id),
                'updatedAt' => $issue->updatedAt?->toIso8601String(),
            ];
        }

        usort($cards, fn (array $a, array $b): int => strnatcasecmp((string) $b['updatedAt'], (string) $a['updatedAt']));

        return [
            'columns' => $this->columns($cards),
            'epics' => array_map(fn (Issue $epic): array => $this->source->card($epic), $graph->ofType(IssueType::Epic)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    private function columns(array $cards): array
    {
        return array_map(fn (State $state): array => [
            'state' => $state->value,
            'issues' => array_values(array_filter($cards, fn (array $card): bool => $card['state'] === $state->value)),
        ], State::cases());
    }
}
