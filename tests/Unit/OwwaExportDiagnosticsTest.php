<?php

namespace Tests\Unit;

use App\Support\OwwaExportDiagnostics;
use Tests\TestCase;

class OwwaExportDiagnosticsTest extends TestCase
{
    public function test_raise_memory_limit_bumps_when_lower(): void
    {
        $before = (string) ini_get('memory_limit');
        $beforeBytes = OwwaExportDiagnostics::memoryLimitBytes($before);

        if ($beforeBytes >= OwwaExportDiagnostics::memoryLimitBytes('512M')) {
            $this->markTestSkipped('Memory limit already at or above 512M.');
        }

        $after = OwwaExportDiagnostics::raiseMemoryLimit('512M');

        $this->assertGreaterThanOrEqual(
            OwwaExportDiagnostics::memoryLimitBytes('512M'),
            OwwaExportDiagnostics::memoryLimitBytes($after),
        );
    }

    public function test_memory_limit_bytes_parses_units(): void
    {
        $this->assertSame(512 * 1024 * 1024, OwwaExportDiagnostics::memoryLimitBytes('512M'));
        $this->assertSame(PHP_INT_MAX, OwwaExportDiagnostics::memoryLimitBytes('-1'));
    }
}
