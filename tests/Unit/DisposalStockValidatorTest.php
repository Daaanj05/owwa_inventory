<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\DisposalStockValidator;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DisposalStockValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_quantity_above_stock_without_unit(): void
    {
        [$office, $item] = $this->seedItemWithStock(2);

        $this->expectException(ValidationException::class);

        app(DisposalStockValidator::class)->validateForCreate([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'disposal_type' => 'unserviceable',
        ]);
    }

    public function test_accepts_quantity_within_stock_without_unit(): void
    {
        [$office, $item] = $this->seedItemWithStock(3);

        app(DisposalStockValidator::class)->validateForCreate([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'disposal_type' => 'unserviceable',
        ]);

        $this->assertTrue(true);
    }

    public function test_unit_linked_disposal_requires_quantity_one(): void
    {
        [$office, $item, $unit] = $this->seedUnit();

        try {
            app(DisposalStockValidator::class)->validateForCreate([
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 2,
                'inventory_unit_id' => $unit->id,
                'disposal_type' => 'unserviceable',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
    }

    public function test_rejects_disposed_inventory_unit(): void
    {
        [$office, $item, $unit] = $this->seedUnit();
        $unit->update(['status' => InventoryUnit::STATUS_DISPOSED]);

        $this->expectException(ValidationException::class);

        app(DisposalStockValidator::class)->validateForCreate([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'inventory_unit_id' => $unit->id,
            'disposal_type' => 'lost_stolen_damaged',
        ]);
    }

    public function test_confirm_validation_allows_existing_unit_on_same_draft(): void
    {
        [$office, $item, $unit] = $this->seedUnit();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $disposal = Disposal::query()->create([
            'reference_code' => '2026-09-0001',
            'item_id' => $item->id,
            'inventory_unit_id' => $unit->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'disposal_date' => now(),
            'disposal_type' => 'unserviceable',
            'property_number' => $unit->property_number,
            'recorded_by' => $custodian->id,
        ]);

        app(DisposalStockValidator::class)->validateRecord($disposal->fresh(['item.category', 'inventoryUnit']));

        $this->assertTrue(true);
    }

    /**
     * @return array{0: Office, 1: Item}
     */
    protected function seedItemWithStock(int $qty): array
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => $qty,
            'unit_cost' => 5000,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(InventoryStockService::class)->forgetMovementTotalsCache();

        return [$office, $item];
    }

    /**
     * @return array{0: Office, 1: Item, 2: InventoryUnit}
     */
    protected function seedUnit(): array
    {
        [$office, $item] = $this->seedItemWithStock(1);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $acquisition = Acquisition::query()
            ->where('item_id', $item->id)
            ->where('office_id', $office->id)
            ->firstOrFail();

        $unit = InventoryUnit::query()->create([
            'property_number' => 'PPE-SCAN-001',
            'acquisition_id' => $acquisition->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'status' => InventoryUnit::STATUS_IN_STOCK,
            'article' => $item->name,
        ]);

        return [$office, $item, $unit];
    }
}
