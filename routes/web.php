<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Dashboard routes. The service provider loads this file only when agentio.ui.enabled is true,
 * inside a group with the ui.path prefix, the ui.domain and the ui.middleware + Authorize middleware.
 */

Route::get('/', fn () => view('agentio::index'))->name('agentio.index');
