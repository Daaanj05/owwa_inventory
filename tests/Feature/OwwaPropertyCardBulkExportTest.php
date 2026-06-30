<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class OwwaPropertyCardBulkExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_property_card_export_returns_workbook_with_sheets_per_item(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/ppe/Accquisition/Appendix 69 - PC.xls'))) {
            $this->markTestSkipped('Appendix 69 PC template is not present in storage/app/templates.');
        }

        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $itemOne = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'PPE-100',
        ]);
        $itemTwo = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'PPE-101',
        ]);

        foreach ([$itemOne, $itemTwo] as $item) {
            Acquisition::query()->create([
                'reference_code' => 'ACQ-'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 1,
                'acquisition_date' => now(),
                'recorded_by' => $custodian->id,
            ]);
        }

        $response = $this->actingAs($custodian)->get(route('owwa.export.bulk.property-cards', [
            'category' => $category->id,
        ]));

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $tmp = tempnam(sys_get_temp_dir(), 'pc_bulk_');
        file_put_contents($tmp, $response->streamedContent());

        try {
            $spreadsheet = IOFactory::load($tmp);
            $this->assertGreaterThanOrEqual(2, $spreadsheet->getSheetCount());
            $this->assertNotNull($spreadsheet->getSheetByName('PPE-100'));
            $this->assertNotNull($spreadsheet->getSheetByName('PPE-101'));
        } finally {
            @unlink($tmp);
        }
    }

    public function test_bulk_property_card_export_returns_404_when_no_stock_rows(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $this->actingAs($custodian)
            ->get(route('owwa.export.bulk.property-cards', ['category' => $category->id]))
            ->assertNotFound();
    }
}
