<?php

namespace Tests\Unit;

use App\Services\LibreOfficePdfConverter;
use App\Support\OwwaLibreOfficeExportGuard;
use Mockery;
use Tests\TestCase;

class OwwaLibreOfficeExportGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_url_needs_libreoffice_skips_fast_dompdf_lookalike_routes(): void
    {
        $this->assertFalse(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/bulk/procurement?document_type=po&format=pdf&date_from=2026-01-01&date_to=2026-12-31',
        ));
        $this->assertFalse(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/bulk/procurement?document_type=pr&format=pdf',
        ));
        $this->assertFalse(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/bulk/procurement?document_type=iar&format=pdf',
        ));
        $this->assertFalse(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/acquisition-paperwork/1/pr-fast-pdf',
        ));
        $this->assertFalse(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/purchase-orders/9/pdf',
        ));
        $this->assertFalse(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/bulk/stock-cards-fast?format=pdf',
        ));
        $this->assertFalse(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/issuance/64/rsmi-fast-pdf',
        ));
        $this->assertFalse(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/bulk/issuances/rsmi?format=pdf&date_from=2026-01-01&date_to=2026-12-31',
        ));
        $this->assertFalse(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/physical-count/3/rpci-fast-pdf',
        ));
        $this->assertFalse(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/physical-count/3/rpcppe-fast-pdf',
        ));
        $this->assertFalse(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/physical-count/3/rpcsp-fast-pdf',
        ));
    }

    public function test_url_needs_libreoffice_still_true_for_official_format_pdf_exports(): void
    {
        $this->assertTrue(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/bulk/stock-cards?category=1&format=pdf',
        ));
        $this->assertTrue(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/issuance/1?format=pdf',
        ));
        $this->assertTrue(OwwaLibreOfficeExportGuard::urlNeedsLibreOffice(
            'https://example.test/reports/owwa/disposals/1?format%3Dpdf',
        ));
    }

    public function test_warn_if_unavailable_is_noop_when_libreoffice_is_available(): void
    {
        $converter = Mockery::mock(LibreOfficePdfConverter::class);
        $converter->shouldReceive('isAvailable')->once()->andReturn(true);
        $converter->shouldNotReceive('binary');
        $this->app->instance(LibreOfficePdfConverter::class, $converter);

        OwwaLibreOfficeExportGuard::warnIfUnavailable();

        $this->addToAssertionCount(1);
    }

    public function test_warn_if_unavailable_reads_binary_when_libreoffice_missing(): void
    {
        $converter = Mockery::mock(LibreOfficePdfConverter::class);
        $converter->shouldReceive('isAvailable')->once()->andReturn(false);
        $converter->shouldReceive('binary')->once()->andReturn('soffice');
        $this->app->instance(LibreOfficePdfConverter::class, $converter);

        OwwaLibreOfficeExportGuard::warnIfUnavailable();

        $this->addToAssertionCount(1);
    }
}
