<?php

namespace Tests\Unit;

use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\InventoryQrLabelService;
use ReflectionMethod;
use Tests\TestCase;

class InventoryQrLabelServiceTest extends TestCase
{
    public function test_label_row_from_unit_includes_letterhead_fields_and_semi_captions(): void
    {
        $office = new Office(['name' => 'OWWA Regional Office IV-A']);
        $category = new ItemCategory(['name' => 'Semi-Expendable']);
        $item = new Item([
            'name' => 'Office Chair',
            'description' => 'Ergonomic mesh chair',
        ]);
        $item->setRelation('category', $category);

        $unit = new InventoryUnit([
            'property_number' => 'SEM-2026-DEMO-0001',
            'status' => InventoryUnit::STATUS_IN_STOCK,
            'unit_cost' => 4500.50,
            'article' => 'Office Chair',
            'description' => 'Ergonomic mesh chair',
        ]);
        $unit->setRelation('item', $item);
        $unit->setRelation('acquisition', null);
        $unit->setRelation('issuance', null);

        $method = new ReflectionMethod(InventoryQrLabelService::class, 'labelRowFromUnit');
        $row = $method->invoke(
            app(InventoryQrLabelService::class),
            $unit,
            $office->name,
            'Administrative Division',
        );

        $this->assertSame('Republic of the Philippines', $row['agency_line_1']);
        $this->assertSame('OVERSEAS WORKERS WELFARE ADMINISTRATION', $row['agency_line_2']);
        $this->assertStringContainsString('Parian, Calamba, Laguna', $row['agency_address']);
        $this->assertSame('', $row['sp_tag_no']);
        $this->assertSame('OWWA Regional Office IV-A - Administrative Division', $row['unit_section']);
        $this->assertSame('Semi-Expendable Property no.', $row['property_number_label']);
        $this->assertSame('Semi-Expendable Property', $row['property_name_label']);
        $this->assertSame('SEM-2026-DEMO-0001', $row['property_number']);
        $this->assertSame('Office Chair', $row['item_name']);
        $this->assertSame('Ergonomic mesh chair', $row['description']);
        $this->assertSame('4500.50', $row['acquisition_cost']);
        $this->assertNotSame('', $row['qr_data_uri']);
    }

    public function test_ppe_captions_use_property_labels(): void
    {
        $category = new ItemCategory(['name' => 'PPE']);
        $item = new Item(['name' => 'Laptop']);
        $item->setRelation('category', $category);

        $unit = new InventoryUnit([
            'property_number' => 'PPE-2026-0001',
            'unit_cost' => 55000,
            'article' => 'Laptop',
        ]);
        $unit->setRelation('item', $item);
        $unit->setRelation('acquisition', null);
        $unit->setRelation('issuance', null);

        $method = new ReflectionMethod(InventoryQrLabelService::class, 'labelRowFromUnit');
        $row = $method->invoke(app(InventoryQrLabelService::class), $unit, 'Regional Office', null);

        $this->assertSame('Property No.', $row['property_number_label']);
        $this->assertSame('Property', $row['property_name_label']);
        $this->assertSame('Regional Office', $row['unit_section']);
    }
}
