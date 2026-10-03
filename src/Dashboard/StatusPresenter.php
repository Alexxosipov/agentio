<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Dashboard;

use Carbon\CarbonImmutable;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Runtime\LoopStatus;
use Obrazmisli\Agentio\Runtime\MergePolicy;
use Obrazmisli\Agentio\Runtime\Session;
use ValueError;

/**
 * The dashboard header: project, loop status, live sessions, merge policy and the YouTrack connection.
 */
final readonly class StatusPresenter
{
    public function __construct(private LoopState $loop, private YouTrackSource $source) {}

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $this->source->attempt(fn (): array => $this->source->issues(), []);
        $status = $this->loop->status();
        $sessions = $this->loop->sessions();

        return [
            'project' => ['key' => $this->source->project(), 'url' => $this->source->projectUrl()],
            'youtrack' => $this->source->health(),
            'loop' => [
                'status' => $status->value,
                'label' => match ($status) {
                    LoopStatus::Running => 'работает',
                    LoopStatus::Stopping => 'останавливается',
                    LoopStatus::Stopped => 'остановлен',
                },
                'pid' => $this->loop->loopPid(),
                'stopRequested' => $this->loop->isStopRequested(),
            ],
            'sessions' => [
                'alive' => count(array_filter($sessions, fn (Session $session): bool => $session->alive)),
                'total' => count($sessions),
            ],
            'mergePolicy' => $this->mergePolicy(),
            'logs' => ['path' => $this->loop->logsPath(), 'exists' => is_dir($this->loop->logsPath())],
            'poll' => max(1, (int) config('agentio.ui.poll', 5)),
            'generatedAt' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * The configured merge policy, or the one declared in CLAUDE.md (like scripts/agent-loop.sh).
     */
    private function mergePolicy(): string
    {
        $configured = config('agentio.merge_policy');
        $configured = is_string($configured) ? $configured : null;

        try {
            return MergePolicy::resolve($configured, base_path('CLAUDE.md'))->value;
        } catch (ValueError) {
            return (string) $configured;
        }
    }
}
