<?php

namespace Tests\Feature;

use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Models\Department;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UcRequisitionToolbarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_uc_requisition_toolbar_places_create_and_archive_icons_on_the_search_row(): void
    {
        [$uc, $office, $department, $pending, $rejected] = $this->seedUcRequisitions();

        $this->actingAs($uc);

        $html = Livewire::test(ListRequisitions::class, [
            'ucTab' => 'received',
            'ucOfficeId' => $office->id,
            'ucDepartmentId' => $department->id,
        ])
            ->assertSee('New Requisition to Supply Custodian')
            ->assertSeeHtml('aria-label="Active"')
            ->assertSeeHtml('aria-label="Archived"')
            ->assertSeeHtml('owwa-uc-requisition-page-actions')
            ->html();

        $this->assertMatchesRegularExpression(
            '/owwa-search-row-actions[\s\S]*New Requisition to Supply Custodian/',
            $html,
        );
        $this->assertStringContainsString('owwa-uc-archive-toggle', $html);

        Livewire::test(ListRequisitions::class, [
            'ucTab' => 'received',
            'ucOfficeId' => $office->id,
            'ucDepartmentId' => $department->id,
            'activeTab' => 'active',
        ])
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$rejected])
            ->set('activeTab', 'archived')
            ->assertCanSeeTableRecords([$rejected])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    /**
     * @return array{0: User, 1: Office, 2: Department, 3: Requisition, 4: Requisition}
     */
    protected function seedUcRequisitions(): array
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => 'ADM',
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);

        $pending = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
            'transaction_number' => 'REQ-UC-ACTIVE',
        ]);
        $rejected = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_REJECTED,
            'transaction_number' => 'REQ-UC-ARCHIVED',
        ]);

        return [$uc, $office, $department, $pending, $rejected];
    }
}
