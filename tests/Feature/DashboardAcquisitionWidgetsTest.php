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
        $office = Office::factory()->create();
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
            'supplier' => 'Supplier Co.',
            'po_date' => now(),
            'iar_date' => now(),
            'requested_by_name' => 'Requester',
            'approved_by_name' => 'Approver',
            'inspection_officer_name' => 'Inspector',
            'custodian_name' => 'Custodian',
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

        $service = app(AcquisitionPaperworkCompletionService::class);
        $service->completePr($paperwork->fresh());
        $service->completePo($paperwork->fresh());
        $service->completeIar($paperwork->fresh());
        $service->recordCustodyReceipts($paperwork->fresh());

        return $paperwork->fresh(['lines.item']);
    }
}
