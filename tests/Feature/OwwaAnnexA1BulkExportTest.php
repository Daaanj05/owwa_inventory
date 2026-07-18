<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Support\ItemPropertyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class OwwaAnnexA1BulkExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_annex_a1_export_creates_tabs_only_for_property_classes_with_items(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $ictItemOne = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Alpha ICT device',
            'property_class' => ItemPropertyClass::Ict,
            'item_code' => 'SEM-100',
        ]);
        $ictItemTwo = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Beta ICT device',
            'property_class' => ItemPropertyClass::Ict,
            'item_code' => 'SEM-101',
        ]);
        $officeItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Office printer',
            'property_class' => ItemPropertyClass::OfficeEquipment,
            'item_code' => 'SEM-200',
        ]);

        foreach ([$ictItemOne, $ictItemTwo, $officeItem] as $item) {
            Acquisition::query()->create([
                'reference_code' => 'ACQ-'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 1,
                'acquisition_date' => now(),
                'recorded_by' => $custodian->id,
            ]);
        }

        $response = $this->actingAs($custodian)->get(route('owwa.export.bulk.annex-a1', [
            'category' => $category->id,
        ]));

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $tmp = tempnam(sys_get_temp_dir(), 'annex_a1_bulk_');
        file_put_contents($tmp, $response->streamedContent());

        try {
            $spreadsheet = IOFactory::load($tmp);
            $this->assertNull($spreadsheet->getSheetByName('SPC'));
            $this->assertNotNull($spreadsheet->getSheetByName('IT'));
            $this->assertNotNull($spreadsheet->getSheetByName('OFFICE EQUIPMENT'));
            $this->assertNull($spreadsheet->getSheetByName('SPORTS EQUIPMENT'));

            $ictSheet = $spreadsheet->getSheetByName('IT');
            $this->assertStringContainsString('Alpha ICT device', (string) $ictSheet->getCell('A12')->getValue());

            $ictValues = '';
            foreach ($ictSheet->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $ictValues .= (string) $cell->getValue()."\n";
                }
            }

            $this->assertStringContainsString('Beta ICT device', $ictValues);
        } finally {
            @unlink($tmp);
        }
    }
}
