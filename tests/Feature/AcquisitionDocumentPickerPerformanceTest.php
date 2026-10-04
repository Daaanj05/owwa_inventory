<?php

namespace Tests\Feature;

use App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Pages\ListInspectionAcceptanceReports;
use App\Filament\Resources\Acquisitions\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Models\AcquisitionPaperwork;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\PurchaseOrderWorkflowService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AcquisitionDocumentPickerPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_picker_options_are_limited_and_searchable_without_loading_all_lines(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);

        $matching = $this->createApprovedPrWithoutPo($category, '2026-10-0022', 'UniquePickerPurpose');
        $this->createApprovedPrWithoutPo($category, '2026-10-0099', 'Other purpose');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $blankSearch = AcquisitionPaperwork::purchaseOrderPickerOptions($category->id, '', 40);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertArrayHasKey($matching->id, $blankSearch);
        $this->assertStringContainsString('2026-10-0022', $blankSearch[$matching->id]);
        $this->assertStringContainsString('UniquePickerPurpose', $blankSearch[$matching->id]);

        $selectsLinesRows = collect($queries)->contains(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'from "acquisition_paperwork_lines"')
                && ! str_contains(strtolower($query['query']), 'count(')
                && ! str_contains(strtolower($query['query']), 'sum('),
        );
        $this->assertFalse($selectsLinesRows, 'Picker must not select full line rows; use aggregates only.');

        $filtered = AcquisitionPaperwork::purchaseOrderPickerOptions($category->id, 'UniquePickerPurpose', 40);
        $this->assertCount(1, $filtered);
        $this->assertArrayHasKey($matching->id, $filtered);
    }

    public function test_inspection_acceptance_picker_options_are_limited_and_searchable(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $po = $this->createApprovedPoWithoutIar($category, 'PO-2026-PICK', 'Picker Supplier Co');
        $this->createApprovedPoWithoutIar($category, 'PO-2026-OTHER', 'Someone Else');

        $blankSearch = PurchaseOrder::inspectionAcceptancePickerOptions($category->id, '', 40);
        $this->assertArrayHasKey($po->id, $blankSearch);
        $this->assertStringContainsString('PO-2026-PICK', $blankSearch[$po->id]);

        $filtered = PurchaseOrder::inspectionAcceptancePickerOptions($category->id, 'Picker Supplier', 40);
        $this->assertCount(1, $filtered);
        $this->assertArrayHasKey($po->id, $filtered);
    }

    public function test_create_iar_select_preloads_approved_po_options_without_typing(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $po = $this->createApprovedPoWithoutIar($category, 'PO-2026-PRELOAD', 'Preload Supplier');
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $po->purchaseRequest?->office_id,
        ]);

        Livewire::actingAs($user)
            ->test(ListInspectionAcceptanceReports::class, ['category' => $category->id])
            ->mountAction('createIar')
            ->assertFormFieldExists('purchase_order_id', function (Select $field) use ($po): bool {
                $options = $field->getOptions();

                return isset($options[$po->id])
                    && str_contains((string) $options[$po->id], 'PO-2026-PRELOAD');
            });
    }

    public function test_create_po_select_preloads_approved_pr_options_without_typing(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $pr = $this->createApprovedPrWithoutPo($category, '2026-10-PRELOAD', 'Preload PR purpose');
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $pr->office_id,
        ]);

        Livewire::actingAs($user)
            ->test(ListPurchaseOrders::class, ['category' => $category->id])
            ->mountAction('createPo')
            ->assertFormFieldExists('acquisition_paperwork_id', function (Select $field) use ($pr): bool {
                $options = $field->getOptions();

                return isset($options[$pr->id])
                    && str_contains((string) $options[$pr->id], '2026-10-PRELOAD');
            });
    }

    protected function createApprovedPrWithoutPo(
        ItemCategory $category,
        string $prNumber,
        string $purpose,
    ): AcquisitionPaperwork {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $user = User::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => $purpose,
            'pr_date' => now()->toDateString(),
            'pr_number' => $prNumber,
            'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
        ]);

        $paperwork->lines()->create([
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => 'pc',
            'quantity' => 2,
            'unit_cost' => 10,
            'amount' => 20,
        ]);

        $completion = app(AcquisitionPaperworkCompletionService::class);
        $completion->submitPr($paperwork->fresh());
        $completion->approvePr($paperwork->fresh());

        return $paperwork->fresh();
    }

    protected function createApprovedPoWithoutIar(
        ItemCategory $category,
        string $poNumber,
        string $supplierName,
    ): PurchaseOrder {
        $pr = $this->createApprovedPrWithoutPo($category, 'PR-'.$poNumber, 'Purpose for '.$poNumber);
        $po = app(PurchaseOrderWorkflowService::class)->createFromApprovedPr($pr);
        $po->update([
            'number' => $poNumber,
            'supplier_name' => $supplierName,
            'supplier_address' => '123 Main St',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => 'OWWA RO',
            'date_of_delivery' => now()->addDays(7)->toDateString(),
            'payment_term' => '30 days',
            'technical_specifications' => 'N/A',
            'po_date' => now()->toDateString(),
        ]);
        $po->lines()->update(['is_ordered' => true, 'unit_cost' => 10, 'amount' => 20]);

        $service = app(PurchaseOrderWorkflowService::class);
        $service->submit($po->fresh(['lines']));
        $service->approve($po->fresh());

        return $po->fresh(['purchaseRequest']);
    }
}
