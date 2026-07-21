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
