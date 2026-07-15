<?php

namespace Tests\Feature;

use App\Filament\Resources\PropertyActionRequests\Pages\ListPropertyActionRequests;
use App\Models\Acquisition;
use App\Models\Department;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PropertyActionRequest;
use App\Models\Requisition;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PropertyActionRequestFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_create_modal_requires_category_and_lines_repeater(): void
    {
        [$employee] = $this->seedEmployeeIssuance();

        Livewire::actingAs($employee)
            ->test(ListPropertyActionRequests::class)
            ->mountAction('create')
            ->assertFormFieldExists('item_category_id')
            ->assertFormFieldExists('lines');
    }

    public function test_create_rejects_submission_without_category(): void
    {
        [$employee, $issuance] = $this->seedEmployeeIssuance();

        Livewire::actingAs($employee)
            ->test(ListPropertyActionRequests::class)
            ->mountAction('create')
            ->setActionData([
                'action_type' => PropertyActionRequest::ACTION_RETURN,
                'reason_code' => 'good_condition',
                'lines' => [
                    ['issuance_id' => $issuance->id],
                ],
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['item_category_id' => 'required']);
    }

    public function test_create_accepts_multi_line_request_with_unique_issuances(): void
    {
        [$employee, $issuanceOne, $issuanceTwo, $category] = $this->seedTwoEmployeeIssuances();

        Livewire::actingAs($employee)
            ->test(ListPropertyActionRequests::class)
            ->mountAction('create')
            ->setActionData([
                'item_category_id' => $category->id,
                'action_type' => PropertyActionRequest::ACTION_RETURN,
                'reason_code' => 'good_condition',
                'lines' => [
                    ['issuance_id' => $issuanceOne->id],
                    ['issuance_id' => $issuanceTwo->id],
                ],
            ])
            ->callMountedAction()
            ->assertNotified();

        $request = PropertyActionRequest::query()->latest('id')->first();
        $this->assertNotNull($request);
        $this->assertSame(2, $request->lines()->count());
        $this->assertSame(PropertyActionRequest::STATUS_DRAFT, $request->status);
    }

    public function test_create_saves_draft_without_submitting(): void
    {
        [$employee, $issuance, $category] = $this->seedEmployeeIssuance();

        Livewire::actingAs($employee)
            ->test(ListPropertyActionRequests::class)
            ->mountAction('create')
            ->setActionData([
                'item_category_id' => $category->id,
                'action_type' => PropertyActionRequest::ACTION_RETURN,
                'reason_code' => 'good_condition',
                'lines' => [
                    ['issuance_id' => $issuance->id],
                ],
            ])
            ->callMountedAction()
            ->assertNotified();

        $request = PropertyActionRequest::query()->latest('id')->first();
        $this->assertNotNull($request);
        $this->assertSame(PropertyActionRequest::STATUS_DRAFT, $request->status);
    }

    public function test_create_rejects_duplicate_issuance_in_lines(): void
    {
        [$employee, $issuance, $category] = $this->seedEmployeeIssuance();

        Livewire::actingAs($employee)
            ->test(ListPropertyActionRequests::class)
            ->mountAction('create')
            ->setActionData([
                'item_category_id' => $category->id,
                'action_type' => PropertyActionRequest::ACTION_RETURN,
                'reason_code' => 'good_condition',
                'lines' => [
                    ['issuance_id' => $issuance->id],
                    ['issuance_id' => $issuance->id],
                ],
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['lines']);
    }

    /**
     * @return array{0: User, 1: Issuance, 2: ItemCategory}
     */
    protected function seedEmployeeIssuance(): array
    {
        [$employee, $issuance, $category] = $this->seedTwoEmployeeIssuances();

        return [$employee, $issuance, $category];
    }

    /**
     * @return array{0: User, 1: Issuance, 2: Issuance, 3: ItemCategory}
     */
    protected function seedTwoEmployeeIssuances(): array
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $issuances = [];

        foreach (['SPLV-TEST-001', 'SPLV-TEST-002'] as $index => $propertyNumber) {
            $acquisition = Acquisition::query()->create([
                'reference_code' => 'ACQ-FORM-'.$index,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 1,
                'unit_cost' => 2500,
                'acquisition_date' => now(),
                'recorded_by' => $custodian->id,
            ]);

            app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);
            $unit = InventoryUnit::query()->where('acquisition_id', $acquisition->id)->first();
            $this->assertNotNull($unit);

            $requisition = Requisition::query()->create([
                'reference_code' => 'REQ-FORM-'.$index,
                'office_id' => $office->id,
                'requested_by' => $employee->id,
                'status' => Requisition::STATUS_ACCEPTED,
            ]);

            $issuance = Issuance::query()->create([
                'requisition_id' => $requisition->id,
                'reference_code' => 'ICS-FORM-'.$index,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => $department->id,
                'quantity' => 1,
                'unit_cost' => 2500,
                'amount' => 2500,
                'issuance_date' => now(),
                'issued_by' => $uc->id,
                'issued_to' => $employee->id,
                'property_number' => $propertyNumber,
            ]);

            $unit->update([
                'status' => InventoryUnit::STATUS_ISSUED,
                'issuance_id' => $issuance->id,
            ]);

            $issuances[] = $issuance;
        }

        return [$employee, $issuances[0], $issuances[1], $category];
    }
}
