<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\OwwaItemReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCardBulkTemplateCloneTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_stock_card_spreadsheet_builds_one_sheet_per_pair(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Consumable/Stock Levels & Recording/Appendix 58 - SC.xlsx'))) {
            $this->markTestSkipped('Appendix 58 SC template is not present in storage/app/templates.');
        }

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $pairs = collect();
        foreach (['CON-CLONE-A', 'CON-CLONE-B'] as $code) {
            $item = Item::factory()->create([
                'item_category_id' => $category->id,
                'item_code' => $code,
            ]);
            Acquisition::query()->create([
                'reference_code' => 'ACQ-'.$code,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 3,
                'acquisition_date' => now(),
                'recorded_by' => $custodian->id,
            ]);
            $pairs->push([
                'item_id' => $item->id,
                'office_id' => $office->id,
                'unit_cost' => null,
            ]);
        }

        $spreadsheet = app(OwwaItemReportService::class)->buildStockCardBulkSpreadsheet($pairs);

        $this->assertSame(2, $spreadsheet->getSheetCount());
        $titles = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $titles[] = $sheet->getTitle();
        }
        $this->assertContains('CON-CLONE-A', $titles);
        $this->assertContains('CON-CLONE-B', $titles);

        $spreadsheet->disconnectWorksheets();
    }
}
