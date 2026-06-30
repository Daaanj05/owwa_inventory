<?php

namespace Tests\Feature;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OwwaModalRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_requisition_view_route_redirects_to_index_with_table_action(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0002',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $this->actingAs($custodian)
            ->get(RequisitionResource::getUrl('view', ['record' => $requisition]))
            ->assertRedirect(RequisitionResource::viewModalUrl($requisition));
    }

    public function test_create_requisition_route_redirects_to_index(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => Office::factory()->create()->id,
        ]);

        $this->actingAs($user)
            ->get(RequisitionResource::getUrl('create'))
            ->assertRedirect(RequisitionResource::getUrl('index'));
    }

    public function test_acquisition_view_route_redirects_to_modal_url(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = \App\Models\ItemCategory::factory()->create(['name' => 'PPE']);
        session()->put('active_item_category_id', $category->id);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $paperwork = \App\Models\AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'recorded_by' => $custodian->id,
            'purpose' => 'Test',
            'pr_date' => now(),
        ]);

        $this->actingAs($custodian)
            ->get(AcquisitionResource::getUrl('view', ['record' => $paperwork]))
            ->assertRedirect(AcquisitionResource::viewModalUrl($paperwork));
    }

    public function test_custodian_can_open_requisition_view_modal_from_table(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0003',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $this->actingAs($custodian);

        Livewire::test(ListRequisitions::class)
            ->assertCanSeeTableRecords([$requisition])
            ->mountTableAction('view', $requisition)
            ->assertActionMounted(TestAction::make('view')->table($requisition));
    }
}
