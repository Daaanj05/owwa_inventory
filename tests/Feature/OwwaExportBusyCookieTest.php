<?php

namespace Tests\Feature;

use App\Http\Middleware\SetOwwaExportDownloadCookie;
use App\Support\OwwaExportDownloadCookie;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use ReflectionProperty;
use Tests\TestCase;

class OwwaExportBusyCookieTest extends TestCase
{
    public function test_export_done_cookie_is_excluded_from_encryption(): void
    {
        $neverEncrypt = new ReflectionProperty(EncryptCookies::class, 'neverEncrypt');
        $cookies = $neverEncrypt->getValue();

        $this->assertContains(
            OwwaExportDownloadCookie::DONE_COOKIE,
            $cookies,
            'owwa_export_done must stay readable by the busy-overlay JS poller',
        );
        $this->assertContains(OwwaExportDownloadCookie::PENDING_COOKIE, $cookies);
    }

    public function test_middleware_sets_plain_download_done_cookie_on_response(): void
    {
        $token = 'owwaTestToken12';
        $request = Request::create('/reports/owwa/test', 'GET', [
            OwwaExportDownloadCookie::TOKEN_QUERY => $token,
        ]);

        $response = (new SetOwwaExportDownloadCookie)->handle(
            $request,
            fn () => response('ok', 200),
        );

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($candidate): bool => $candidate->getName() === OwwaExportDownloadCookie::DONE_COOKIE);

        $this->assertNotNull($cookie);
        $this->assertSame($token, $cookie->getValue());
        $this->assertFalse($cookie->isHttpOnly());
    }
}
