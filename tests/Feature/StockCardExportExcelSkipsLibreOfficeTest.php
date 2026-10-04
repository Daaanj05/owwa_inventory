<?php

namespace Tests\Feature;

use App\Http\Controllers\OwwaBulkExportController;
use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\LibreOfficePdfConverter;
use App\Services\OwwaItemReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StockCardExportExcelSkipsLibreOfficeTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_stock_card_excel_does_not_call_libreoffice_converter(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Consumable/Stock Levels & Recording/Appendix 58 - SC.xlsx'))) {
            $this->markTestSkipped('Appendix 58 SC template is not present in storage/app/templates.');
        }

        $converter = Mockery::mock(LibreOfficePdfConverter::class);
        $converter->shouldReceive('isAvailable')->never();
        $converter->shouldReceive('convertXlsxBinary')->never();
        $converter->shouldReceive('binary')->never();
        $this->app->instance(LibreOfficePdfConverter::class, $converter);

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'CON-XLSX-LO',
        ]);
        Acquisition::query()->create([
            'reference_code' => 'ACQ-XLSX-LO'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $this->actingAs($custodian);

        $response = $this->get(route('owwa.export.bulk.stock-cards', [
            'category' => $category->id,
            'pairs' => $item->id.':'.$office->id,
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertInstanceOf(OwwaBulkExportController::class, app(OwwaBulkExportController::class));
        $this->assertInstanceOf(OwwaItemReportService::class, app(OwwaItemReportService::class));
    }
}
