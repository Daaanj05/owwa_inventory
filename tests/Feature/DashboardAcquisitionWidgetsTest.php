<?php

namespace Tests\Feature;

use App\Filament\Widgets\RecentAcquisitionsWidget;
use App\Filament\Widgets\TopAcquiredProductsWidget;
use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\InspectionAcceptanceReportWorkflowService;
use App\Services\PurchaseOrderWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardAcquisitionWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_supply_custodian_sees_recent_acquisition_widget_data(): void
    {
        $paperwork = $this->createReceivedPaperwork();
        $custodian = User::query()->find($paperwork->recorded_by);

        $this->actingAs($custodian);

        Livewire::test(RecentAcquisitionsWidget::class)
            ->assertOk()
            ->assertSee('Recent acquisition')
            ->assertSee($paperwork->reference_code)
            ->assertSee('Supplier Co.')
            ->assertSee('₱500.00');
    }

    public function test_supply_custodian_sees_top_acquired_products_widget_data(): void
    {
        $paperwork = $this->createReceivedPaperwork();
        $custodian = User::query()->find($paperwork->recorded_by);
        $item = $paperwork->lines->first()->item;

        $this->actingAs($custodian);

        Livewire::test(TopAcquiredProductsWidget::class)
            ->assertOk()
            ->assertSee('Top 5 acquired product')
            ->assertSee($item->name)
            ->assertSee('1');
    }

    public function test_acquisition_dashboard_widgets_are_hidden_from_unit_consolidator(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $this->assertFalse(RecentAcquisitionsWidget::canView());
        $this->assertFalse(TopAcquiredProductsWidget::canView());
    }

    protected function createReceivedPaperwork(): AcquisitionPaperwork
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $requestingOffice = Office::factory()->create([
            'name' => 'OWWA Satellite Office — Laguna',
            'code' => 'OWWA-LAG',
            'is_satellite' => true,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Consumable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'CON-DASH-1',
            'name' => 'Dashboard Test Item',
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $requestingOffice->id,
            'recorded_by' => $user->id,
            'purpose' => 'Office supplies',
            'pr_date' => now(),
            'requested_by_name' => 'Requester',
            'approved_by_name' => 'Approver',
        ]);

        AcquisitionPaperworkLine::query()->create([
            'acquisition_paperwork_id' => $paperwork->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => $item->unit ?? 'piece',
            'quantity' => 1,
            'unit_cost' => 500,
            'amount' => 500,
        ]);

        $completion = app(AcquisitionPaperworkCompletionService::class);
        $completion->completePr($paperwork->fresh());

        $poService = app(PurchaseOrderWorkflowService::class);
        $po = $poService->createFromApprovedPr($paperwork->fresh());
        $po->update([
            'supplier_name' => 'Supplier Co.',
            'supplier_address' => '123 Main St',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => 'OWWA RO',
            'date_of_delivery' => now()->addDays(7)->toDateString(),
            'payment_term' => '30 days',
            'technical_specifications' => 'N/A',
            'po_date' => now()->toDateString(),
        ]);
        $po->lines()->update([
            'is_ordered' => true,
            'po_quantity' => 1,
            'unit_cost' => 500,
            'amount' => 500,
        ]);
        $poService->submit($po->fresh(['lines']));
        $poService->approve($po->fresh());

        $iarService = app(InspectionAcceptanceReportWorkflowService::class);
        $iar = $iarService->createFromApprovedPo($po->fresh());
        $iar->update([
            'invoice_number' => 'INV100',
            'invoice_date' => now()->subDays(2)->toDateString(),
            'date_inspected' => now()->subDay()->toDateString(),
            'date_received' => now()->toDateString(),
            'inspection_officer_name' => 'Inspector',
            'custodian_name' => 'Custodian',
            'iar_date' => now()->subDays(3)->toDateString(),
        ]);
        $iarService->submit($iar->fresh(['lines']));
        $iarService->approve($iar->fresh());
        $iarService->recordCustodyReceipts($iar->fresh());

        return $paperwork->fresh(['lines.item']);
    }
}
