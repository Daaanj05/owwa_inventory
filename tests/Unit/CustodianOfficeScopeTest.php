<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\User;
use App\Support\CustodianOfficeScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustodianOfficeScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_supply_custodian_with_office_has_fixed_inventory_office(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->assertSame($office->id, CustodianOfficeScope::inventoryOfficeId($user));
        $this->assertTrue(CustodianOfficeScope::hasFixedInventoryOffice($user));
    }

    public function test_unit_consolidator_does_not_have_fixed_inventory_office(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $this->assertNull(CustodianOfficeScope::inventoryOfficeId($user));
        $this->assertFalse(CustodianOfficeScope::hasFixedInventoryOffice($user));
    }

    public function test_assert_office_allowed_blocks_other_office_for_custodian(): void
    {
        $home = Office::factory()->create();
        $other = Office::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $home->id,
        ]);

        CustodianOfficeScope::assertOfficeAllowed($home->id, $user);

        $this->expectException(ValidationException::class);
        CustodianOfficeScope::assertOfficeAllowed($other->id, $user);
    }

    public function test_office_options_returns_only_custodian_office(): void
    {
        $home = Office::factory()->create(['name' => 'Regional Office']);
        Office::factory()->create(['name' => 'Satellite Office']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $home->id,
        ]);

        $options = CustodianOfficeScope::officeOptions($user);

        $this->assertCount(1, $options);
        $this->assertSame($home->id, $options[0]['id']);
        $this->assertSame('Regional Office', $options[0]['name']);
    }

    public function test_apply_office_column_filters_physical_count_sessions(): void
    {
        $home = Office::factory()->create();
        $other = Office::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $home->id,
        ]);

        PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $home->id,
            'count_date' => now(),
        ]);
        PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $other->id,
            'count_date' => now(),
        ]);

        $count = CustodianOfficeScope::applyOfficeColumn(PhysicalCountSession::query(), 'office_id', $user)->count();

        $this->assertSame(1, $count);
    }

    public function test_apply_office_column_filters_acquisitions_for_custodian(): void
    {
        $home = Office::factory()->create();
        $other = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $home->id,
        ]);

        Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $home->id,
            'quantity' => 1,
            'unit_cost' => 75000,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);
        Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $other->id,
            'quantity' => 1,
            'unit_cost' => 75000,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        $count = CustodianOfficeScope::applyOfficeColumn(Acquisition::query(), 'office_id', $user)->count();

        $this->assertSame(1, $count);
    }

    public function test_apply_office_column_filters_disposals_for_custodian(): void
    {
        $home = Office::factory()->create();
        $other = Office::factory()->create();
        $item = Item::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $home->id,
        ]);

        Disposal::query()->create([
            'item_id' => $item->id,
            'office_id' => $home->id,
            'quantity' => 1,
            'disposal_date' => now(),
        ]);
        Disposal::query()->create([
            'item_id' => $item->id,
            'office_id' => $other->id,
            'quantity' => 1,
            'disposal_date' => now(),
        ]);

        $count = CustodianOfficeScope::applyOfficeColumn(Disposal::query(), 'office_id', $user)->count();

        $this->assertSame(1, $count);
    }
}
