<?php

namespace App\Http\Middleware;

use App\Support\OwwaExportDiagnostics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RaiseOwwaExportMemoryLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $before = (string) ini_get('memory_limit');
        $after = OwwaExportDiagnostics::raiseMemoryLimit('512M');
        OwwaExportDiagnostics::registerOomGuard($request->path());

        OwwaExportDiagnostics::info('memory_limit_raised', [
            'path' => $request->path(),
            'route' => $request->route()?->getName(),
            'before' => $before,
            'after' => $after,
            'query' => $request->query(),
        ]);

        return $next($request);
    }
}
