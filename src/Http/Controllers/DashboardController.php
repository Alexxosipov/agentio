<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Obrazmisli\Agentio\YouTrack\IssueRepository;

/**
 * The dashboard page: a Blade shell; the script fills it from the JSON endpoints.
 */
final class DashboardController
{
    public function __invoke(Request $request, IssueRepository $issues): View
    {
        return view('agentio::index', [
            'styles' => AssetController::url('app.css'),
            'script' => AssetController::url('app.js'),
            'config' => [
                'project' => $issues->project(),
                'youtrackUrl' => $issues->client()->isConfigured() ? $issues->client()->baseUrl() : null,
                'poll' => max(1, (int) config('agentio.ui.poll', 5)),
                'actions' => (bool) config('agentio.ui.actions', true),
                'csrf' => $request->hasSession() ? $request->session()->token() : null,
                'endpoints' => [
                    'status' => route('agentio.api.status'),
                    'sessions' => route('agentio.api.sessions'),
                    'pipeline' => route('agentio.api.pipeline'),
                    'board' => route('agentio.api.board'),
                    'events' => route('agentio.api.events'),
                    'loopLog' => route('agentio.api.loop-log'),
                    'epic' => route('agentio.api.epic', ['id' => '__ID__']),
                    'review' => route('agentio.api.review', ['id' => '__ID__']),
                    'diff' => route('agentio.api.diff', ['id' => '__ID__']),
                    'accept' => route('agentio.api.accept', ['id' => '__ID__']),
                    'rework' => route('agentio.api.rework', ['id' => '__ID__']),
                ],
            ],
        ]);
    }
}
