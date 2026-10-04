<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Obrazmisli\Agentio\Http\Controllers\ApiController;
use Obrazmisli\Agentio\Http\Controllers\AssetController;
use Obrazmisli\Agentio\Http\Controllers\DashboardController;

/*
 * Dashboard routes. The service provider loads this file only when agentio.ui.enabled is true,
 * inside a group with the ui.path prefix, the ui.domain and the ui.middleware + Authorize middleware.
 */

Route::get('/', DashboardController::class)->name('agentio.index');

Route::get('assets/{file}', AssetController::class)
    ->where('file', 'app\.(css|js)')
    ->name('agentio.asset');

Route::prefix('api')->name('agentio.api.')->group(function (): void {
    Route::get('status', [ApiController::class, 'status'])->name('status');
    Route::get('sessions', [ApiController::class, 'sessions'])->name('sessions');
    Route::get('pipeline', [ApiController::class, 'pipeline'])->name('pipeline');
    Route::get('board', [ApiController::class, 'board'])->name('board');
    Route::get('events', [ApiController::class, 'events'])->name('events');
    Route::get('loop-log', [ApiController::class, 'loopLog'])->name('loop-log');

    Route::where(['id' => '[A-Za-z][A-Za-z0-9_]*-[0-9]+|__ID__'])->group(function (): void {
        Route::get('epics/{id}', [ApiController::class, 'epic'])->name('epic');
        Route::get('epics/{id}/review', [ApiController::class, 'review'])->name('review');
        Route::get('epics/{id}/diff', [ApiController::class, 'diff'])->name('diff');
        Route::post('epics/{id}/accept', [ApiController::class, 'accept'])->name('accept');
        Route::post('epics/{id}/rework', [ApiController::class, 'rework'])->name('rework');
    });
});
