<?php

namespace App\Http\Middleware;

use App\Support\OwwaExportDiagnostics;
use App\Support\OwwaExportDownloadCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SetOwwaExportDownloadCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        OwwaExportDiagnostics::info('request_reached_server', [
            'path' => $request->path(),
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
            'has_token' => $request->query(OwwaExportDownloadCookie::TOKEN_QUERY) !== null,
            'user_id' => $request->user()?->id,
            'pairs' => $request->query('pairs'),
            'category' => $request->query('category'),
            'format' => $request->query('format', 'xlsx'),
        ]);

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $throwable) {
            OwwaExportDiagnostics::error('request_exception', $throwable, [
                'path' => $request->path(),
                'route' => $request->route()?->getName(),
            ]);

            throw $throwable;
        }

        OwwaExportDiagnostics::info('response_prepared', [
            'path' => $request->path(),
            'route' => $request->route()?->getName(),
            'status' => $response->getStatusCode(),
            'content_type' => $response->headers->get('content-type'),
            'content_disposition' => $response->headers->get('content-disposition'),
        ]);

        $token = OwwaExportDownloadCookie::tokenFromRequest(
            $request->query(OwwaExportDownloadCookie::TOKEN_QUERY)
        );

        if ($token === null) {
            return $response;
        }

        $response->headers->setCookie(Cookie::create(
            OwwaExportDownloadCookie::DONE_COOKIE,
            $token,
            now()->addMinutes(2),
            '/',
            null,
            $request->isSecure(),
            false,
            false,
            Cookie::SAMESITE_LAX,
        ));

        return $response;
    }
}
