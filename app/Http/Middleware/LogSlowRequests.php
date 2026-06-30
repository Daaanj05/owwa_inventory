<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogSlowRequests
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isLocal()) {
            return $next($request);
        }

        $thresholdMs = (int) (env('SLOW_REQUEST_MS', 500));
        $start = microtime(true);

        $response = null;

        try {
            $response = $next($request);

            return $response;
        } finally {
            $elapsedMs = (microtime(true) - $start) * 1000;

            if ($elapsedMs >= $thresholdMs) {
                Log::warning('Slow request detected.', [
                    'time_ms' => (int) round($elapsedMs),
                    'method' => $request->getMethod(),
                    'path' => $request->path(),
                    'route' => optional($request->route())->getName(),
                    'status' => $response?->getStatusCode(),
                    'is_livewire' => $request->is('livewire/*') || $request->is('livewire-*/*'),
                ]);
            }
        }
    }
}
