<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureExpandedErpPhase
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('erp.phase_mode', 'legacy') !== 'expanded') {
            abort(404);
        }

        return $next($request);
    }
}
