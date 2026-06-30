<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Support\SupplyOfficeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplyOfficeResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_returns_regional_office_when_not_satellite(): void
    {
        Office::factory()->create(['name' => 'Satellite', 'is_satellite' => true]);
        $regional = Office::factory()->create(['name' => 'Regional Office', 'is_satellite' => false]);

        $this->assertSame($regional->id, app(SupplyOfficeResolver::class)->resolve());
    }

    public function test_resolve_falls_back_to_single_custodian_office(): void
    {
        Office::factory()->create(['is_satellite' => true]);
        $custodianOffice = Office::factory()->create(['is_satellite' => true]);
        User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $custodianOffice->id,
        ]);

        $this->assertSame($custodianOffice->id, app(SupplyOfficeResolver::class)->resolve());
    }

    public function test_resolve_returns_null_when_no_regional_or_unique_custodian(): void
    {
        Office::factory()->create(['is_satellite' => true]);

        $this->assertNull(app(SupplyOfficeResolver::class)->resolve());
    }

    public function test_regional_stock_available_for_item_at_supply_office(): void
    {
        $regional = Office::factory()->create(['is_satellite' => false]);
        $satellite = Office::factory()->create(['is_satellite' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $regional->id,
        ]);

        Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $regional->id,
            'quantity' => 12,
            'unit_cost' => 10,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $satellite->id,
            'quantity' => 3,
            'unit_cost' => 10,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        $resolver = app(SupplyOfficeResolver::class);

        $this->assertSame($regional->id, $resolver->resolve());
        $this->assertSame(12, app(\App\Services\InventoryStockService::class)->getStock($item->id, (int) $resolver->resolve()));
    }
}
