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

    public function test_resolve_prefers_designated_regional_supply_office(): void
    {
        $earlierByName = Office::factory()->create([
            'name' => 'AAA Other Regional',
            'is_satellite' => false,
            'is_regional_supply' => false,
        ]);
        $designated = Office::factory()->create([
            'name' => 'ZZZ Designated Supply',
            'is_satellite' => false,
            'is_regional_supply' => true,
        ]);

        $this->assertSame($designated->id, app(SupplyOfficeResolver::class)->resolve());
        $this->assertNotSame($earlierByName->id, app(SupplyOfficeResolver::class)->resolve());
    }

    public function test_setting_regional_supply_clears_other_offices(): void
    {
        $first = Office::factory()->create([
            'name' => 'First',
            'is_satellite' => false,
            'is_regional_supply' => true,
        ]);
        $second = Office::factory()->create([
            'name' => 'Second',
            'is_satellite' => false,
            'is_regional_supply' => false,
        ]);

        $second->update(['is_regional_supply' => true]);

        $this->assertFalse($first->fresh()->is_regional_supply);
        $this->assertTrue($second->fresh()->is_regional_supply);
        $this->assertSame($second->id, app(SupplyOfficeResolver::class)->resolve());
    }

    public function test_regional_supply_cannot_remain_satellite(): void
    {
        $office = Office::factory()->create([
            'is_satellite' => true,
            'is_regional_supply' => false,
        ]);

        $office->update(['is_regional_supply' => true]);

        $this->assertFalse($office->fresh()->is_satellite);
        $this->assertTrue($office->fresh()->is_regional_supply);
    }

    public function test_resolve_returns_regional_office_when_not_satellite(): void
    {
        Office::factory()->create(['name' => 'Satellite', 'is_satellite' => true]);
        $regional = Office::factory()->create(['name' => 'Regional Office', 'is_satellite' => false]);

        $this->assertSame($regional->id, app(SupplyOfficeResolver::class)->resolve());
    }

    public function test_resolve_prefers_custodian_office_over_alphabetical_non_satellite(): void
    {
        $emptyRegional = Office::factory()->create([
            'name' => 'AAA Empty Regional',
            'is_satellite' => false,
            'is_regional_supply' => false,
        ]);
        $custodianOffice = Office::factory()->create([
            'name' => 'ZZZ Stock Office',
            'is_satellite' => false,
            'is_regional_supply' => false,
        ]);
        User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $custodianOffice->id,
        ]);

        $this->assertSame($custodianOffice->id, app(SupplyOfficeResolver::class)->resolve());
        $this->assertNotSame($emptyRegional->id, app(SupplyOfficeResolver::class)->resolve());
    }

    public function test_backfill_migration_sets_regional_supply_on_owwa_iva_when_unset(): void
    {
        $office = Office::factory()->create([
            'code' => 'OWWA-IVA',
            'name' => 'OWWA Regional Office IV-A',
            'is_satellite' => false,
            'is_regional_supply' => false,
        ]);

        $migration = require database_path('migrations/2026_07_09_142458_backfill_regional_supply_office.php');
        $migration->up();

        $this->assertTrue($office->fresh()->is_regional_supply);
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
        $regional = Office::factory()->create([
            'is_satellite' => false,
            'is_regional_supply' => true,
        ]);
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
