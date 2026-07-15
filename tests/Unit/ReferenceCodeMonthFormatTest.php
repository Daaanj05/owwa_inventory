<?php

namespace Tests\Unit;

use App\Services\ReferenceCodeService;
use Database\Seeders\ReferenceSeriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReferenceCodeMonthFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_yearly_series_uses_actual_month_in_control_number(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $this->seed(ReferenceSeriesSeeder::class);

        $code = app(ReferenceCodeService::class)->forRequisition();

        $this->assertSame('2026-07-0001', $code);

        Carbon::setTestNow();
    }

    public function test_yearly_series_continues_serial_across_months(): void
    {
        Carbon::setTestNow('2026-01-20 10:00:00');

        $this->seed(ReferenceSeriesSeeder::class);

        $service = app(ReferenceCodeService::class);
        $service->forRequisition();

        Carbon::setTestNow('2026-07-10 10:00:00');

        $second = $service->forRequisition();

        $this->assertSame('2026-07-0002', $second);

        Carbon::setTestNow();
    }
}
