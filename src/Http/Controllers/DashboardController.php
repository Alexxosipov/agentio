<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Http\Controllers;

use Illuminate\Contracts\View\View;
use Obrazmisli\Agentio\YouTrack\IssueRepository;

/**
 * The dashboard page: a Blade shell; the script fills it from the JSON endpoints.
 */
final class DashboardController
{
    public function __invoke(IssueRepository $issues): View
    {
        return view('agentio::index', [
            'styles' => AssetController::url('app.css'),
            'script' => AssetController::url('app.js'),
            'config' => [
                'project' => $issues->project(),
                'youtrackUrl' => $issues->client()->isConfigured() ? $issues->client()->baseUrl() : null,
                'poll' => max(1, (int) config('agentio.ui.poll', 5)),
                'endpoints' => [
                    'status' => route('agentio.api.status'),
                    'sessions' => route('agentio.api.sessions'),
                    'pipeline' => route('agentio.api.pipeline'),
                    'board' => route('agentio.api.board'),
                    'events' => route('agentio.api.events'),
                    'loopLog' => route('agentio.api.loop-log'),
                    'epic' => route('agentio.api.epic', ['id' => '__ID__']),
                ],
            ],
        ]);
    }
}
