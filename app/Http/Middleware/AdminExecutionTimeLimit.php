<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminExecutionTimeLimit
{
    /**
     * Give the admin panel more time to render (e.g. dashboard widgets).
     */
    public function handle(Request $request, Closure $next): Response
    {
        @set_time_limit(60);

        return $next($request);
    }
}
