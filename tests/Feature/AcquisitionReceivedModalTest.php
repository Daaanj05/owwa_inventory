<?php

namespace Tests\Feature;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AcquisitionReceivedModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_received_view_modal_url_uses_view_action(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createReceivedPaperwork();

        $url = AcquisitionResource::viewModalUrl($paperwork);

        $this->assertStringContainsString('tableAction=view', $url);
    }

    public function test_in_progress_view_modal_url_uses_edit_action(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createInProgressPaperwork();

        $url = AcquisitionResource::viewModalUrl($paperwork);

        $this->assertStringContainsString('tableAction=edit', $url);
    }

    public function test_view_route_redirects_received_paperwork_to_view_modal(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createReceivedPaperwork();
        $custodian = User::query()->find($paperwork->recorded_by);

        $this->actingAs($custodian)
            ->get(AcquisitionResource::getUrl('view', ['record' => $paperwork]))
            ->assertRedirect(AcquisitionResource::viewModalUrl($paperwork));
    }

    public function test_custodian_can_open_view_modal_for_received_acquisition(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createReceivedPaperwork();
        $custodian = User::query()->find($paperwork->recorded_by);
        session()->put('active_item_category_id', $paperwork->item_category_id);

        $this->actingAs($custodian);

        Livewire::withQueryParams(['category' => (string) $paperwork->item_category_id])
            ->test(ListAcquisitions::class)
            ->assertCanSeeTableRecords([$paperwork])
            ->mountTableAction('view', $paperwork)
            ->assertActionMounted(TestAction::make('view')->table($paperwork));
    }

    public function test_received_acquisition_row_action_is_view(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createReceivedPaperwork();
        $custodian = User::query()->find($paperwork->recorded_by);
        session()->put('active_item_category_id', $paperwork->item_category_id);

        $this->actingAs($custodian);

        $table = Livewire::withQueryParams(['category' => (string) $paperwork->item_category_id])
            ->test(ListAcquisitions::class)
            ->instance()
            ->getTable();

        $this->assertSame('view', $table->getRecordAction($paperwork));
    }

    public function test_in_progress_acquisition_row_action_is_edit(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createInProgressPaperwork();
        $custodian = User::query()->find($paperwork->recorded_by);

        $this->actingAs($custodian);

        $table = Livewire::withQueryParams(['category' => (string) $paperwork->item_category_id])
            ->test(ListAcquisitions::class)
            ->instance()
            ->getTable();

        $this->assertSame('edit', $table->getRecordAction($paperwork));
    }

    public function test_received_acquisition_with_edit_url_opens_view_modal(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createReceivedPaperwork();
        $custodian = User::query()->find($paperwork->recorded_by);
        session()->put('active_item_category_id', $paperwork->item_category_id);

        $this->actingAs($custodian);

        Livewire::test(ListAcquisitions::class)
            ->set('defaultTableAction', 'edit')
            ->set('defaultTableActionRecord', (string) $paperwork->getKey())
            ->assertSet('defaultTableAction', 'view');
    }

    public function test_paperwork_resource_view_modal_url_uses_view_for_received(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $paperwork = $this->createReceivedPaperwork();

        $url = \App\Filament\Resources\Acquisitions\Paperwork\AcquisitionPaperworkResource::viewModalUrl($paperwork);

        $this->assertStringContainsString('tableAction=view', $url);
    }

    protected function createInProgressPaperwork(): AcquisitionPaperwork
    {
        $office = Office::factory()->create();
        $requestingOffice = Office::factory()->create([
            'name' => 'OWWA Satellite Office — Laguna',
            'code' => 'OWWA-LAG',
            'is_satellite' => true,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN, 'office_id' => $office->id]);

        session()->put('active_item_category_id', $category->id);

        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $requestingOffice->id,
            'recorded_by' => $user->id,
            'purpose' => 'Office equipment',
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

        return $paperwork->fresh();
    }

    protected function createReceivedPaperwork(): AcquisitionPaperwork
    {
        $paperwork = $this->createInProgressPaperwork();

        $service = app(AcquisitionPaperworkCompletionService::class);
        $service->completePr($paperwork->fresh());
        $service->completePo($paperwork->fresh());
        $service->completeIar($paperwork->fresh());
        $service->recordCustodyReceipts($paperwork->fresh());

        return $paperwork->fresh();
    }
}
