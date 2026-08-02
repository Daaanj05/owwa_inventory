<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\OwwaTemplateExportService;
use App\Support\OwwaCellMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionRisStockExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_ris_export_uses_stock_at_request_when_stock_available_is_not_set(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0700',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => 'Office supplies',
        ]);

        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 4,
            'stock_at_request' => 0,
        ]);

        $values = app(OwwaTemplateExportService::class)->cellValuesForRequisition($requisition->fresh('items.item'));
        $risMap = OwwaCellMapping::form('RIS');
        $detail = (array) ($risMap['detail'] ?? []);
        $columns = (array) ($detail['columns'] ?? []);
        $startRow = (int) ($detail['start_row'] ?? 12);
        $noCol = $columns['stock_no_col'] ?? 'F';

        $this->assertSame('✓', $values[OwwaCellMapping::columnCell($noCol, $startRow)] ?? null);
    }
}
