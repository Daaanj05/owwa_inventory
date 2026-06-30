<?php

namespace Tests\Unit;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Services\AnalyticsDateRangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsDateRangeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_calendar_year_range(): void
    {
        $service = app(AnalyticsDateRangeService::class);
        $range = $service->currentYearRange();

        $year = now()->year;
        $this->assertSame("{$year}-01-01", $range['from']->toDateString());
        $this->assertSame("{$year}-12-31", $range['to']->toDateString());
        $this->assertStringContainsString('Calendar year', $range['label']);
    }

    public function test_long_view_range_clamps_from_to_earliest_issuance(): void
    {
        $item = Item::factory()->create();
        $office = Office::factory()->create();

        Issuance::withoutEvents(function () use ($item, $office): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-OLD',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => null,
                'quantity' => 1,
                'issuance_date' => '2020-06-15',
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-NEW',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => null,
                'quantity' => 1,
                'issuance_date' => '2025-03-10',
            ]);
        });

        $service = app(AnalyticsDateRangeService::class);
        $range = $service->longViewRange(60);

        $this->assertSame('2025-03-10', $range['to']->toDateString());
        $this->assertTrue($range['from']->gte(Carbon::parse('2020-06-01')->startOfDay()));
    }

    public function test_resolve_from_widget_filters_uses_explicit_dates(): void
    {
        $service = app(AnalyticsDateRangeService::class);
        $resolved = $service->resolveFromWidgetFilters([
            'analytics_scope' => AnalyticsDateRangeService::SCOPE_LONG_VIEW,
            'date_from' => '2023-01-01',
            'date_to' => '2023-12-31',
        ]);

        $this->assertSame('2023-01-01', $resolved['from']->toDateString());
        $this->assertTrue($resolved['includeYearInLabels']);
    }

    public function test_get_range_for_scope_dispatches_to_long_view(): void
    {
        $service = app(AnalyticsDateRangeService::class);
        $range = $service->getRangeForScope(AnalyticsDateRangeService::SCOPE_LONG_VIEW, 60);

        $this->assertArrayHasKey('from', $range);
        $this->assertArrayHasKey('to', $range);
        $this->assertArrayHasKey('label', $range);
    }
}
