<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Obrazmisli\Agentio\Agentio;
use Symfony\Component\HttpFoundation\Response;

final class Authorize
{
    /**
     * Abort with 403 unless the request may access the dashboard.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Agentio::check($request), 403);

        return $next($request);
    }
}
