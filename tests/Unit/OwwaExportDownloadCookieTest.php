<?php

namespace Tests\Unit;

use App\Support\OwwaExportDownloadCookie;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OwwaExportDownloadCookieTest extends TestCase
{
    #[Test]
    public function it_accepts_valid_tokens(): void
    {
        $this->assertSame('owwaabc12345', OwwaExportDownloadCookie::tokenFromRequest('owwaabc12345'));
    }

    #[Test]
    public function it_rejects_invalid_tokens(): void
    {
        $this->assertNull(OwwaExportDownloadCookie::tokenFromRequest(null));
        $this->assertNull(OwwaExportDownloadCookie::tokenFromRequest(''));
        $this->assertNull(OwwaExportDownloadCookie::tokenFromRequest('bad token'));
        $this->assertNull(OwwaExportDownloadCookie::tokenFromRequest('short'));
    }

    #[Test]
    public function it_builds_same_origin_download_urls_with_token(): void
    {
        $url = OwwaExportDownloadCookie::sameOriginDownloadUrl(
            'https://capstoneproject.test:8443/reports/owwa/bulk/stock-cards?category=1&format=pdf',
            'owwaTestToken12',
        );

        $this->assertSame(
            '/reports/owwa/bulk/stock-cards?category=1&format=pdf&owwa_download_token=owwaTestToken12',
            $url,
        );
    }

    #[Test]
    public function make_token_is_cookie_safe(): void
    {
        $token = OwwaExportDownloadCookie::makeToken();

        $this->assertSame($token, OwwaExportDownloadCookie::tokenFromRequest($token));
    }

    #[Test]
    public function form_logo_assets_exist_for_pdf(): void
    {
        $this->assertFileExists(public_path(config('owwa_mail.logos.owwa')));
        $this->assertFileExists(public_path(config('owwa_mail.logos.bagong_pilipinas')));
    }
}
