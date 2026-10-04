<?php

namespace Tests\Feature;

use App\Filament\Pages\StockLevels;
use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\InventoryStockService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class StockLevelsPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_paginator_uses_stock_levels_page_url_after_livewire_rerender(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        for ($i = 1; $i <= 11; $i++) {
            $item = Item::factory()->create([
                'item_category_id' => $category->id,
                'item_code' => 'SEM-PG-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'name' => "Item {$i}",
            ]);

            $acquisition = Acquisition::query()->create([
                'reference_code' => "ACQ-PG-{$i}",
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 1,
                'unit_cost' => 100,
                'acquisition_date' => now(),
                'recorded_by' => $user->id,
            ]);

            app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);
        }

        $this->actingAs($user);
        session()->put('active_item_category_id', $category->id);

        $component = Livewire::test(StockLevels::class, ['category' => $category->id])
            ->set('search', 'Item');

        $paginator = $component->instance()->getStockLevels();

        $this->assertStringContainsString('/stock-levels', $paginator->url(2));
        $this->assertStringNotContainsString('/livewire-', $paginator->url(2));
    }

    public function test_category_page_does_not_query_once_per_catalog_item(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        for ($i = 1; $i <= 30; $i++) {
            $item = Item::factory()->create([
                'item_category_id' => $category->id,
            ]);

            Acquisition::query()->create([
                'reference_code' => 'ACQ-SL-'.$i,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 1,
                'unit_cost' => 10,
                'acquisition_date' => now()->toDateString(),
                'recorded_by' => $user->id,
            ]);
        }

        app(InventoryStockService::class)->forgetMovementTotalsCache();
        $this->actingAs($user);

        $component = Livewire::test(StockLevels::class, ['category' => $category->id]);
        $instance = $component->instance();

        $cache = new \ReflectionProperty($instance, 'resolvedStockLevels');
        $cache->setValue($instance, null);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $summary = $instance->getStockLevelsSummary();
        $paginator = $instance->getStockLevels();

        $queryCount = count(DB::getQueryLog());

        $this->assertSame(30, $summary['total']);
        $this->assertLessThanOrEqual(10, $paginator->count());
        $this->assertSame(30, $paginator->total());
        $this->assertLessThan(
            20,
            $queryCount,
            "Stock levels issued {$queryCount} queries for one category.",
        );
    }
}
