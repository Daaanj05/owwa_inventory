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

    public function test_employee_transaction_number_omits_month(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $this->seed(ReferenceSeriesSeeder::class);

        $service = app(ReferenceCodeService::class);

        $this->assertSame('2026-0001', $service->forEmployeeRequisitionTransaction());
        $this->assertSame('2026-0002', $service->forEmployeeRequisitionTransaction());

        Carbon::setTestNow('2026-12-01 10:00:00');

        $this->assertSame('2026-0003', $service->forEmployeeRequisitionTransaction());

        Carbon::setTestNow('2027-01-05 10:00:00');

        $this->assertSame('2027-0001', $service->forEmployeeRequisitionTransaction());

        Carbon::setTestNow();
    }

    public function test_acquisition_paperwork_reference_uses_ap_year_series_format(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');

        $this->seed(ReferenceSeriesSeeder::class);

        $service = app(ReferenceCodeService::class);

        $this->assertSame('AP-2026-0001', $service->forAcquisitionPaperwork());
        $this->assertSame('AP-2026-0002', $service->forAcquisitionPaperwork());

        Carbon::setTestNow();
    }
}
