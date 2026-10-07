<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Dashboard;

use Carbon\CarbonImmutable;
use Obrazmisli\Agentio\Runtime\LoopState;
use Obrazmisli\Agentio\Runtime\LoopStatus;
use Obrazmisli\Agentio\Runtime\Session;
use Obrazmisli\Agentio\Runtime\UsageLimit;

/**
 * The dashboard header: project, loop status (and its pause at the usage limit of Claude Code), live sessions and
 * the YouTrack connection.
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
        $limit = $this->loop->usageLimit();

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
                // The pause at the usage limit of Claude Code: when the loop resumes the work.
                'usageLimit' => $limit === null ? null : [
                    'resumesAt' => $limit->resetsAt?->toIso8601String(),
                    'label' => UsageLimit::windowLabel($limit->window),
                ],
            ],
            'sessions' => [
                'alive' => count(array_filter($sessions, fn (Session $session): bool => $session->alive)),
                'total' => count($sessions),
            ],
            'logs' => ['path' => $this->loop->logsPath(), 'exists' => is_dir($this->loop->logsPath())],
            'poll' => max(1, (int) config('agentio.ui.poll', 5)),
            'generatedAt' => CarbonImmutable::now()->toIso8601String(),
        ];
    }
}
