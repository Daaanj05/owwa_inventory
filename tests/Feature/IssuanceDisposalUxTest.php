<?php

namespace Tests\Feature;

use App\Filament\Resources\Issuances\Actions\IssuanceViewActions;
use App\Models\Disposal;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\DisposalWorkflowService;
use App\Services\InventoryStockService;
use App\Services\OwwaTemplateExportService;
use App\Support\DisposalExportLayout;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuanceDisposalUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuance_export_actions_do_not_require_signatory_confirmation(): void
    {
        $excel = IssuanceViewActions::exportOwwaAction();
        $pdf = IssuanceViewActions::exportPdfAction();

        $this->assertFalse($excel->isConfirmationRequired());
        $this->assertFalse($pdf->isConfirmationRequired());
        $this->assertFalse(method_exists(IssuanceViewActions::class, 'printViewAction'));
    }

    public function test_rsmi_date_range_export_includes_ris_number(): void
    {
        if (! is_file(storage_path('app/templates/Consumable/Issuances/Appendix 64 - RSMI.xls'))
            && ! is_file(base_path('resources/owwa-templates/Consumable/Issuances/Appendix 64 - RSMI.xls'))) {
            $this->markTestSkipped('RSMI template is not installed.');
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $risNo = 'RIS-UX-'.uniqid();
        $requisition = Requisition::query()->create([
            'reference_code' => $risNo,
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'issuance_date' => now()->toDateString(),
            'reference_code' => '2026-08-0501',
            'issued_by' => $custodian->id,
        ]);

        session(['active_item_category_id' => $category->id]);

        $response = $this->actingAs($custodian)->get(route('owwa.export.bulk.issuances.rsmi', [
            'date_from' => now()->subDay()->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
            'category' => $category->id,
        ]));

        $response->assertOk();
        $spreadsheet = app(OwwaTemplateExportService::class)->issuancesRsmiFilledSpreadsheet(
            Issuance::query()->with('requisition')->get()
        );
        $values = [];
        foreach ($spreadsheet->getActiveSheet()->getRowIterator(12, 12) as $row) {
            $cell = $spreadsheet->getActiveSheet()->getCell('A'.$row->getRowIndex())->getValue();
            $values[] = (string) $cell;
        }
        $spreadsheet->disconnectWorksheets();

        $this->assertContains($risNo, $values);
    }

    public function test_wmr_export_uses_auto_item_number_not_inspection_field(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'unit' => 'pc']);
        $user = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $disposal = Disposal::query()->create([
            'reference_code' => '2026-08-0901',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'disposal_date' => now()->toDateString(),
            'disposal_type' => 'waste_sale',
            'disposal_mode' => 'destroyed',
            'wmr_inspection_item_no' => 99,
            'recorded_by' => $user->id,
            'custodian_printed_name' => 'Custodian',
            'approved_by_printed_name' => 'Approver',
            'inspection_officer_printed_name' => 'Inspector',
            'witness_printed_name' => 'Witness',
        ]);

        $values = DisposalExportLayout::cellValuesForWmr($disposal->fresh(['item', 'office']));
        $this->assertSame(1, (int) ($values['A13'] ?? 0));
        $this->assertNotContains(99, array_map('intval', array_filter($values, 'is_numeric')));
    }

    public function test_disposal_draft_does_not_reduce_stock_until_confirmed(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN, 'office_id' => $office->id]);

        \App\Models\Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 10,
            'unit_cost' => 5,
            'acquisition_date' => now()->toDateString(),
            'recorded_by' => $user->id,
        ]);

        $stock = app(InventoryStockService::class);
        $before = $stock->getStockForUnitCost($item->id, $office->id, 5.0);

        $disposal = Disposal::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 3,
            'disposal_date' => now()->toDateString(),
            'disposal_type' => 'waste_sale',
            'disposal_mode' => 'destroyed',
            'acquisition_cost' => 5,
            'recorded_by' => $user->id,
            'custodian_printed_name' => 'Custodian',
            'approved_by_printed_name' => 'Approver',
            'inspection_officer_printed_name' => 'Inspector',
            'witness_printed_name' => 'Witness',
        ]);

        $this->assertFalse($disposal->fresh()->isConfirmed());
        $this->assertSame($before, $stock->getStockForUnitCost($item->id, $office->id, 5.0));

        app(DisposalWorkflowService::class)->confirm($disposal->fresh(['batch']));

        $this->assertTrue($disposal->fresh()->isConfirmed());
        $this->assertFalse($disposal->fresh()->isEditable());
        $this->assertSame($before - 3, $stock->getStockForUnitCost($item->id, $office->id, 5.0));
    }

    public function test_disposal_report_route_requires_date_range(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $this->actingAs($custodian)
            ->get(route('owwa.export.bulk.disposals.report'))
            ->assertStatus(422);
    }
}
