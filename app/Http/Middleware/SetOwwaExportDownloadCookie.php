<?php

namespace App\Http\Middleware;

use App\Support\OwwaExportDownloadCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SetOwwaExportDownloadCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

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
