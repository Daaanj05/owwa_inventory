<?php

namespace Tests\Feature;

use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\ProcurementSignatoryName;
use App\Models\UacsObjectCode;
use App\Support\ItemPropertyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcquisitionPaperworkCatalogIdentifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_line_stock_number_uses_inventory_item_no_for_semi(): void
    {
        Office::factory()->create([
            'code' => 'RWO4A',
            'is_regional_supply' => true,
            'is_satellite' => false,
        ]);
        $uacs = UacsObjectCode::query()->create([
            'code' => '106',
            'name' => 'Placeholder',
            'is_active' => true,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'property_class' => ItemPropertyClass::InformationTechnology,
            'uacs_object_code_id' => $uacs->id,
        ]);

        $paperwork = AcquisitionPaperwork::query()->create([
            'item_category_id' => $category->id,
            'office_id' => Office::query()->first()->id,
            'requesting_office_id' => Office::query()->first()->id,
            'phase' => AcquisitionPaperwork::PHASE_PR,
            'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
            'po_status' => AcquisitionPaperwork::STATUS_DRAFT,
            'iar_status' => AcquisitionPaperwork::STATUS_DRAFT,
            'requested_by_name' => 'Maria Santos',
            'approved_by_name' => 'Roberto Cruz',
        ]);

        $line = AcquisitionPaperworkLine::query()->create([
            'acquisition_paperwork_id' => $paperwork->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => 'piece',
            'quantity' => 1,
            'unit_cost' => 1000,
        ]);

        $this->assertSame($item->fresh()->semi_expendable_property_number, $line->stockNumber());
        $this->assertDatabaseHas(ProcurementSignatoryName::class, [
            'name' => 'Maria Santos',
            'role' => ProcurementSignatoryName::ROLE_REQUESTED,
        ]);
        $this->assertDatabaseHas(ProcurementSignatoryName::class, [
            'name' => 'Roberto Cruz',
            'role' => ProcurementSignatoryName::ROLE_APPROVED,
        ]);
    }
}
