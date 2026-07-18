<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\RequisitionPurchaseRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZeroStockRequisitionPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_picker_rows_include_requester_office_qty_and_zero_regional_stock(): void
    {
        $office = Office::factory()->create(['name' => 'Satellite Office A']);
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'unit' => 'box',
        ]);
        $consolidator = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'name' => 'Ana Consolidator',
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => 'REQ-ZERO-001',
            'office_id' => $office->id,
            'requested_by' => $consolidator->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $requisition->items()->create([
            'item_id' => $item->id,
            'quantity' => 7,
        ]);

        $rows = app(RequisitionPurchaseRequestService::class)
            ->zeroStockRequisitionPickerRows($category->id);

        $this->assertCount(1, $rows);
        $this->assertSame($requisition->id, $rows[0]['id']);
        $this->assertSame('REQ-ZERO-001', $rows[0]['reference']);
        $this->assertSame('Ana Consolidator', $rows[0]['requested_by']);
        $this->assertSame('Satellite Office A', $rows[0]['office']);
        $this->assertSame(7, $rows[0]['quantity_requested']);
        $this->assertSame(0, $rows[0]['regional_stock']);
    }
}
