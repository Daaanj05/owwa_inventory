<?php

namespace Tests\Unit;

use App\Models\PhysicalCountSession;
use App\Services\ReferenceCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PhysicalCountReferenceCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_physical_count_reference_uses_form_year_month_series_format(): void
    {
        Carbon::setTestNow('2026-09-22 10:00:00');

        $service = app(ReferenceCodeService::class);

        $this->assertSame('RPCI-2026-09-0001', $service->forPhysicalCount(PhysicalCountSession::TYPE_RPCI));
        $this->assertSame('RPCPPE-2026-09-0001', $service->forPhysicalCount(PhysicalCountSession::TYPE_RPCPPE));
        $this->assertSame('RPCSP-2026-09-0001', $service->forPhysicalCount(PhysicalCountSession::TYPE_RPCSP));

        Carbon::setTestNow();
    }

    public function test_physical_count_series_continues_across_months_and_resets_yearly(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');

        $service = app(ReferenceCodeService::class);
        $this->assertSame('RPCI-2026-01-0001', $service->forPhysicalCount(PhysicalCountSession::TYPE_RPCI));

        Carbon::setTestNow('2026-09-22 10:00:00');
        $this->assertSame('RPCI-2026-09-0002', $service->forPhysicalCount(PhysicalCountSession::TYPE_RPCI));

        Carbon::setTestNow('2027-01-05 10:00:00');
        $this->assertSame('RPCI-2027-01-0001', $service->forPhysicalCount(PhysicalCountSession::TYPE_RPCI));

        Carbon::setTestNow();
    }

    public function test_creating_session_assigns_rpci_style_reference_when_blank(): void
    {
        Carbon::setTestNow('2026-09-22 10:00:00');

        $office = \App\Models\Office::factory()->create();

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'count_date' => now(),
        ]);

        $this->assertSame('RPCI-2026-09-0001', $session->reference_code);

        Carbon::setTestNow();
    }
}
