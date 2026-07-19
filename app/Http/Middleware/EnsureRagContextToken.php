<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRagContextToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.rag.context_token');

        if (! is_string($expected) || $expected === '') {
            abort(403, 'RAG context endpoint is not configured.');
        }

        $provided = $request->bearerToken();

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid or missing RAG context token.');
        }

        return $next($request);
    }
}
