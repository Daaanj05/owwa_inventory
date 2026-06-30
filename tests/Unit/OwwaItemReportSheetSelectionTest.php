<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PhysicalCountSession;
use App\Services\OwwaItemReportService;
use App\Support\ItemPropertyClass;
use Tests\TestCase;

class OwwaItemReportSheetSelectionTest extends TestCase
{
    public function test_consumable_stock_card_export_uses_sc_sheet(): void
    {
        $category = new ItemCategory(['name' => 'Consumables']);
        $item = new Item(['name' => 'Paper']);
        $item->setRelation('category', $category);

        $sheet = app(OwwaItemReportService::class)->resolveItemReportSheet($item, 'sc');

        $this->assertSame('SC', $sheet['sheetName']);
    }

    public function test_semi_item_annex_a1_loads_master_spc_template_sheet(): void
    {
        $category = new ItemCategory(['name' => 'Semi-Expendable']);
        $item = new Item([
            'name' => 'Tablet',
            'property_class' => ItemPropertyClass::Ict,
        ]);
        $item->setRelation('category', $category);

        $sheet = app(OwwaItemReportService::class)->resolveItemReportSheet($item, 'annex_a1');

        $this->assertSame('SPC', $sheet['sheetName']);
    }

    public function test_rpcsp_physical_count_uses_session_property_class_sheet(): void
    {
        $session = new PhysicalCountSession([
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'property_class' => ItemPropertyClass::MedicalEquipment,
        ]);

        $sheet = app(OwwaItemReportService::class)->resolvePhysicalCountSheet($session);

        $this->assertSame('MEDICAL EQUIPMENT', $sheet['sheetName']);
    }
}
