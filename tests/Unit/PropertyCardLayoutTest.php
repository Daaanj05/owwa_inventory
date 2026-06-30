<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\OwwaItemReportService;
use App\Support\PropertyCardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyCardLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_acquisition_pc_maps_single_receipt_row(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'PPE-001',
            'name' => 'Office Desk',
        ]);
        $office = Office::factory()->create(['name' => 'OWWA RO4A', 'fund_cluster' => '01']);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-2026-001',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'unit_cost' => 75000,
            'acquisition_date' => '2026-01-15',
            'remarks' => 'Delivered complete',
        ]);
        $acquisition->load(['item', 'office']);

        $values = PropertyCardLayout::buildFromAcquisition($acquisition);

        $this->assertStringContainsString('OWWA RO4A', (string) ($values['B6'] ?? ''));
        $this->assertStringContainsString('PPE-001', (string) ($values['I9'] ?? ''));
        $this->assertSame('2026-01-15', $values['B12'] ?? null);
        $this->assertSame('ACQ-2026-001', $values['C12'] ?? null);
        $this->assertSame(2, $values['D12'] ?? null);
        $this->assertSame(150000.0, $values['I12'] ?? null);
        $this->assertSame('Delivered complete', $values['J12'] ?? null);
    }

    public function test_property_card_includes_receipt_and_issue_rows(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'item_code' => 'PPE-002']);
        $office = Office::factory()->create();

        Acquisition::query()->create([
            'reference_code' => 'ACQ-R1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'unit_cost' => 75000,
            'acquisition_date' => '2026-02-01',
        ]);

        $values = app(OwwaItemReportService::class)->cellValuesForPropertyCard($item, $office, $office->id);

        $this->assertSame('2026-02-01', $values['B12'] ?? null);
        $this->assertSame('ACQ-R1', $values['C12'] ?? null);
        $this->assertSame(5, $values['D12'] ?? null);
        $this->assertSame(375000.0, $values['I12'] ?? null);
    }
}
