<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Runtime;

/**
 * Events read forward from a session log, and where to continue reading.
 */
final readonly class SessionLogPage
{
    /**
     * @param  list<SessionEvent>  $events
     * @param  int  $nextOffset  Pass to SessionLog::read() to get the events written after this page
     * @param  bool  $hasMore  Whether complete lines remain after $nextOffset
     */
    public function __construct(
        public array $events,
        public int $nextOffset,
        public bool $hasMore,
    ) {}

    /**
     * @return array{events: list<array<string, mixed>>, nextOffset: int, hasMore: bool}
     */
    public function toArray(): array
    {
        return [
            'events' => array_map(fn (SessionEvent $event): array => $event->toArray(), $this->events),
            'nextOffset' => $this->nextOffset,
            'hasMore' => $this->hasMore,
        ];
    }
}
