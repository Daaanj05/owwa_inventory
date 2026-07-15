<?php

namespace Tests\Unit;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\RequisitionCompileService;
use App\Support\OwwaReferenceLabels;
use App\Support\RequisitionLineDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionLineDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_identifier_table_header_is_asset_no(): void
    {
        $this->assertSame('Asset No.', OwwaReferenceLabels::assetIdentifierTableHeader());
    }

    public function test_identifier_resolves_through_compiled_parent_issuance(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create(['item_code' => 'STK-LINE-01']);

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-3001',
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $employeeRequisition->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $consolidated = app(RequisitionCompileService::class)->createConsolidatedRequisition(
            $uc,
            collect([$employeeRequisition]),
            [['item_id' => $item->id, 'quantity' => 1]],
            'Purpose',
        );

        Issuance::query()->create([
            'reference_code' => '2026-01-0501',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'requisition_id' => $consolidated->id,
            'quantity' => 1,
            'issuance_date' => now()->toDateString(),
            'issued_by' => $uc->id,
        ]);

        $line->refresh();

        $this->assertSame('STK-LINE-01', RequisitionLineDisplay::identifierValue($line));
    }
}
