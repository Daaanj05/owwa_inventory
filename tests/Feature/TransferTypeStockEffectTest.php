<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Transfer;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Services\StockLedgerViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransferTypeStockEffectTest extends TestCase
{
    use RefreshDatabase;

    public function test_outgoing_types_deduct_the_from_office_and_receipt_the_destination(): void
    {
        $from = Office::factory()->create(['name' => 'Laguna']);
        $to = Office::factory()->create(['name' => 'Cavite']);
        $next = Office::factory()->create(['name' => 'Batangas']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Paper Cutter',
        ]);
        $user = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $this->createAcquisition($item->id, $from->id, 5);

        $this->createTransfer($item, $from, $to, 3, Transfer::TYPE_DONATION, $user);
        $this->createTransfer($item, $to, $next, 2, Transfer::TYPE_RELOCATE, $user);

        $stock = app(InventoryStockService::class);
        $stock->forgetMovementTotalsCache();

        $this->assertSame(2, $stock->getStock($item->id, $from->id));
        $this->assertSame(1, $stock->getStock($item->id, $to->id));
        $this->assertSame(2, $stock->getStock($item->id, $next->id));

        $fromCard = app(StockLedgerViewService::class)->present($item, $from);
        $toCard = app(StockLedgerViewService::class)->present($item, $to);

        $this->assertTrue(collect($fromCard['rows'])->contains(
            fn (array $row): bool => $row['type_label'] === 'Donation' && (int) $row['issue_qty'] === 3,
        ));
        $this->assertTrue(collect($toCard['rows'])->contains(
            fn (array $row): bool => $row['type_label'] === 'Donation' && (int) $row['receipt_qty'] === 3,
        ));
        $this->assertTrue(collect($toCard['rows'])->contains(
            fn (array $row): bool => $row['type_label'] === 'Relocate' && (int) $row['issue_qty'] === 2,
        ));
    }

    public function test_return_to_stock_increases_only_regional_stock_and_is_labeled_as_a_return(): void
    {
        $from = Office::factory()->create(['name' => 'Laguna']);
        $regional = Office::factory()->create([
            'name' => 'OWWA Regional Office IV-A',
            'is_regional_supply' => true,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Heavy-Duty Stapler',
        ]);
        $user = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $this->createIssuance($item->id, $from->id, 1);

        $this->createTransfer($item, $from, $regional, 1, Transfer::TYPE_RETURN, $user, 'SPLV-RET-9');

        $stock = app(InventoryStockService::class);
        $stock->forgetMovementTotalsCache();

        $this->assertSame(0, $stock->getStock($item->id, $from->id));
        $this->assertSame(1, $stock->getStock($item->id, $regional->id));

        $regionalCard = app(StockLedgerViewService::class)->present($item, $regional);
        $fromCard = app(StockLedgerViewService::class)->present($item, $from);

        $this->assertTrue(collect($regionalCard['rows'])->contains(
            fn (array $row): bool => $row['type_label'] === 'Return to stock' && (int) $row['receipt_qty'] === 1,
        ));
        $this->assertFalse(collect($regionalCard['rows'])->contains(
            fn (array $row): bool => $row['type_label'] === 'Transfer out',
        ));
        $this->assertFalse(collect($fromCard['rows'])->contains(
            fn (array $row): bool => in_array($row['type_label'], ['Return to stock', 'Transfer out'], true),
        ));
    }

    protected function createAcquisition(int $itemId, int $officeId, int $quantity): void
    {
        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-TYPE-'.$itemId.'-'.$officeId.'-'.uniqid(),
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => $quantity,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createIssuance(int $itemId, int $officeId, int $quantity): void
    {
        DB::table('issuances')->insert([
            'reference_code' => 'ISS-TYPE-'.$itemId.'-'.$officeId.'-'.uniqid(),
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => $quantity,
            'property_number' => 'SPLV-RET-9',
            'issuance_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createTransfer(
        Item $item,
        Office $from,
        Office $to,
        int $quantity,
        string $type,
        User $user,
        ?string $propertyNumber = null,
    ): void {
        Transfer::withoutEvents(function () use ($item, $from, $to, $quantity, $type, $user, $propertyNumber): void {
            Transfer::query()->create([
                'reference_code' => 'PTR-'.$type.'-'.uniqid(),
                'item_id' => $item->id,
                'from_office_id' => $from->id,
                'to_office_id' => $to->id,
                'quantity' => $quantity,
                'transfer_type' => $type,
                'property_number' => $propertyNumber,
                'transfer_date' => now()->toDateString(),
                'recorded_by' => $user->id,
            ]);
        });
    }
}
