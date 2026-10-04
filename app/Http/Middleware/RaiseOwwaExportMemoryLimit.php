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
        // DomPDF all-stocks batches (200–500 pages) need well above 512M once logos/tables are rendered.
        $after = OwwaExportDiagnostics::raiseMemoryLimit('2048M');
        @set_time_limit(300);
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
