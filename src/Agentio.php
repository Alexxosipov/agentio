<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class Agentio
{
    /**
     * The callback that decides whether a request may access the dashboard.
     *
     * @var (Closure(Request): bool)|null
     */
    private static ?Closure $authUsing = null;

    /**
     * Set the callback that decides whether a request may access the dashboard.
     *
     * Passing null restores the default: the "viewAgentio" gate.
     *
     * @param  (Closure(Request): bool)|null  $callback
     */
    public static function auth(?Closure $callback): void
    {
        self::$authUsing = $callback;
    }

    /**
     * Determine whether the given request may access the dashboard.
     */
    public static function check(Request $request): bool
    {
        if (self::$authUsing !== null) {
            return (self::$authUsing)($request);
        }

        return Gate::forUser($request->user())->check('viewAgentio');
    }
}
