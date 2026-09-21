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

    public function test_rolling_months_range_includes_current_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 12:00:00'));

        $service = app(AnalyticsDateRangeService::class);
        $range = $service->rollingMonthsRange(6);

        $this->assertSame('2026-04-01', $range['from']->toDateString());
        $this->assertSame('2026-09-30', $range['to']->toDateString());
        $this->assertSame('Last 6 months', $range['label']);

        Carbon::setTestNow();
    }

    public function test_resolve_from_widget_filters_defaults_to_rolling_six_months(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 12:00:00'));

        $service = app(AnalyticsDateRangeService::class);
        $resolved = $service->resolveFromWidgetFilters([]);

        $this->assertSame('2026-04-01', $resolved['from']->toDateString());
        $this->assertSame('2026-09-30', $resolved['to']->toDateString());
        $this->assertFalse($resolved['includeYearInLabels']);

        Carbon::setTestNow();
    }

    public function test_resolve_from_widget_filters_uses_explicit_dates(): void
    {
        $service = app(AnalyticsDateRangeService::class);
        $resolved = $service->resolveFromWidgetFilters([
            'date_from' => '2023-01-01',
            'date_to' => '2023-12-31',
        ]);

        $this->assertSame('2023-01-01', $resolved['from']->toDateString());
        $this->assertFalse($resolved['includeYearInLabels']);
    }

    public function test_resolve_from_widget_filters_sets_year_labels_for_long_ranges(): void
    {
        $service = app(AnalyticsDateRangeService::class);
        $resolved = $service->resolveFromWidgetFilters([
            'date_from' => '2022-01-01',
            'date_to' => '2024-06-30',
        ]);

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
