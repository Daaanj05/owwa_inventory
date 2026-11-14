<?php

namespace Tests\Feature;

use App\Filament\Resources\Issuances\IssuanceResource;
use App\Filament\Resources\Requisitions\Actions\CustodianRequisitionActions;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Filament\Resources\Requisitions\Schemas\RequisitionIssuanceFormSchema;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Acquisition;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\RequisitionSourceEndorsement;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Services\OwwaTemplateExportService;
use App\Services\RequisitionFulfillmentService;
use App\Support\RequisitionLineDisplay;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class RecordIssuanceFromRequisitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuance_resource_cannot_create_ad_hoc(): void
    {
        $this->assertFalse(IssuanceResource::canCreate());
    }

    public function test_custodian_can_accept_and_issue_from_pending_requisition(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-FEAT-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 50,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var User $uc */
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        /** @var User $custodian */
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0005',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 5,
        ]);

        $this->actingAs($custodian);

        Livewire::test(ListRequisitions::class)
            ->assertCanSeeTableRecords([$requisition])
            ->mountTableAction('view', $requisition)
            ->assertActionVisible(TestAction::make('acceptAndIssue'));

        $result = app(RequisitionFulfillmentService::class)->issueLines(
            $requisition,
            $custodian,
            [
                [
                    'requisition_item_id' => $line->id,
                    'quantity_to_issue' => 5,
                ],
            ],
            '2026-04-01',
        );

        $this->assertSame(1, $result['created']);

        $issuance = Issuance::query()->with('requisition')->first();

        $this->assertNotNull($issuance);
        $this->assertSame($requisition->id, $issuance->requisition_id);
        $this->assertNotSame($requisition->reference_code, $issuance->reference_code);
        $this->assertSame(Requisition::STATUS_ACCEPTED, $requisition->fresh()->status);

        $values = app(OwwaTemplateExportService::class)->cellValuesForIssuance(
            $issuance->loadMissing(['office', 'department', 'item', 'requisition']),
            'Consumable/Issuances/Appendix 64 - RSMI.xls'
        );

        $this->assertSame($requisition->reference_code, $values['A12']);
        $this->assertStringContainsString($issuance->reference_code, (string) $values['G6']);
    }

    public function test_accept_and_issue_action_saves_for_approval_without_issuing(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-FEAT-2',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 50,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var User $uc */
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        /** @var User $custodian */
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0200',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $this->actingAs($custodian);

        Livewire::test(ListRequisitions::class)
            ->mountTableAction('view', $requisition)
            ->callAction(TestAction::make('acceptAndIssue'), [
                'issuance_date' => '2026-06-07',
                'ris_requested_by_name' => $uc->name,
                'ris_requested_by_designation' => 'Admin',
                'ris_requested_by_date' => '2026-06-07',
                'ris_approved_by_name' => 'Approving Officer',
                'ris_approved_by_designation' => 'Chief',
                'ris_approved_by_date' => '2026-06-07',
                'ris_issued_by_name' => 'Supply Custodian',
                'ris_issued_by_designation' => 'Supply Officer',
                'ris_issued_by_date' => '2026-06-07',
                'ris_received_by_name' => $uc->name,
                'ris_received_by_designation' => 'Admin',
                'ris_received_by_date' => '2026-06-07',
                'lines' => RequisitionIssuanceFormSchema::defaultLines($requisition->fresh(), false),
            ])
            ->assertNotified();

        $requisition->refresh();

        $this->assertSame(0, Issuance::query()->where('requisition_id', $requisition->id)->count());
        $this->assertSame(Requisition::STATUS_PENDING, $requisition->status);
        $this->assertSame(Requisition::RIS_APPROVAL_FOR_APPROVAL, $requisition->ris_approval_status);
        $this->assertNotNull($requisition->pending_issue_payload);
        $this->assertSame(0, (int) ($line->fresh()->quantity_issued ?? 0));
        $this->assertSame('Approving Officer', $requisition->ris_approved_by_name);
    }

    public function test_custodian_reject_action_was_removed(): void
    {
        $this->assertFalse(method_exists(
            CustodianRequisitionActions::class,
            'rejectAction',
        ));
    }

    public function test_review_and_issue_modal_uses_wide_record_modal_shell(): void
    {
        foreach ([
            CustodianRequisitionActions::reviewAndIssueAction(),
            CustodianRequisitionActions::issueRemainderAction(),
        ] as $action) {
            $width = $action->getModalWidth();
            $widthValue = $width instanceof \Filament\Support\Enums\Width
                ? $width->value
                : (string) $width;

            $this->assertSame(OwwaFormModalDefaults::WIDTH_WIDE, $widthValue);
            $this->assertSame(
                \Filament\Support\Enums\Alignment::Start,
                $action->getModalAlignment(),
            );
            $this->assertSame(
                \Filament\Support\Enums\Alignment::End,
                $action->getModalFooterActionsAlignment(),
            );
            $this->assertStringContainsString(
                'owwa-record-modal',
                (string) ($action->getExtraModalWindowAttributes()['class'] ?? ''),
            );
            $this->assertStringContainsString(
                'owwa-requisition-issue-modal',
                (string) ($action->getExtraModalWindowAttributes()['class'] ?? ''),
            );
        }
    }

    public function test_accept_and_issue_modal_shows_signatories_and_lines_table_fields(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-FEAT-MODAL',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 50,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0300',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 3,
        ]);

        $this->actingAs($custodian);

        Livewire::test(ListRequisitions::class)
            ->mountTableAction('view', $requisition)
            ->mountAction(TestAction::make('acceptAndIssue'))
            ->assertFormFieldExists('issuance_date')
            ->assertFormFieldExists('ris_requested_by_name')
            ->assertFormFieldExists('ris_requested_by_designation')
            ->assertFormFieldExists('ris_requested_by_date')
            ->assertFormFieldExists('ris_approved_by_name')
            ->assertFormFieldExists('ris_issued_by_name')
            ->assertFormFieldExists('ris_issued_by_designation')
            ->assertFormFieldExists('ris_received_by_name')
            ->assertFormFieldExists('ris_received_by_designation')
            ->assertFormFieldDoesNotExist('ris_requested_by_date_display')
            ->assertFormFieldDoesNotExist('ris_approved_by_date_display')
            ->assertFormFieldDoesNotExist('accounting_staff_printed_name')
            ->assertFormFieldExists('lines');
    }

    public function test_mixed_category_requisition_issues_to_separate_category_lists(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $department = \App\Models\Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations',
            'code' => '01',
        ]);
        $consumableCategory = ItemCategory::factory()->create(['name' => 'Consumables']);
        $semiCategory = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $consumableItem = Item::factory()->create(['item_category_id' => $consumableCategory->id]);
        $semiItem = Item::factory()->create([
            'item_category_id' => $semiCategory->id,
            'property_class' => \App\Support\ItemPropertyClass::OfficeEquipment,
            'estimated_useful_life' => '5 yrs',
        ]);

        /** @var User $custodian */
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-MIX-'.$consumableItem->id,
            'item_id' => $consumableItem->id,
            'office_id' => $office->id,
            'quantity' => 50,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $semiAcquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-MIX-'.$semiItem->id,
            'item_id' => $semiItem->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 1000,
            'acquisition_date' => now()->toDateString(),
            'recorded_by' => $custodian->id,
        ]);

        InventoryUnit::query()->create([
            'property_number' => 'SEMI-MIX-001',
            'acquisition_id' => $semiAcquisition->id,
            'item_id' => $semiItem->id,
            'office_id' => $office->id,
            'status' => InventoryUnit::STATUS_IN_STOCK,
        ]);

        /** @var User $uc */
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0300',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $consumableLine = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $consumableItem->id,
            'quantity' => 2,
        ]);

        $semiLine = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $semiItem->id,
            'quantity' => 1,
        ]);

        $this->assertTrue($requisition->fresh()->hasMixedCategories());

        $result = app(RequisitionFulfillmentService::class)->issueLines(
            $requisition,
            $custodian,
            [
                [
                    'requisition_item_id' => $consumableLine->id,
                    'quantity_to_issue' => 2,
                ],
                [
                    'requisition_item_id' => $semiLine->id,
                    'quantity_to_issue' => 1,
                ],
            ],
            '2026-06-08',
        );

        $this->assertSame(2, $result['created']);
        $this->assertSame(
            ['Consumables' => 1, 'Semi-Expendable' => 1],
            $result['categories'],
        );

        $summary = RequisitionLineDisplay::formatIssuanceCategorySummary(2, $result['categories']);
        $this->assertStringContainsString('Consumables (1)', $summary);
        $this->assertStringContainsString('Semi-Expendable (1)', $summary);

        $this->assertSame(2, Issuance::query()->where('requisition_id', $requisition->id)->count());

        session(['active_item_category_id' => $consumableCategory->id]);
        $consumableIssuanceIds = IssuanceResource::getEloquentQuery()->pluck('id')->all();
        $this->assertCount(1, $consumableIssuanceIds);
        $this->assertSame(
            $consumableItem->id,
            Issuance::query()->find($consumableIssuanceIds[0])?->item_id,
        );

        session(['active_item_category_id' => $semiCategory->id]);
        $semiIssuanceIds = IssuanceResource::getEloquentQuery()->pluck('id')->all();
        $this->assertCount(1, $semiIssuanceIds);
        $this->assertSame(
            $semiItem->id,
            Issuance::query()->find($semiIssuanceIds[0])?->item_id,
        );
    }

    public function test_custodian_issues_split_quantities_per_employee_from_consolidated_ris(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'name' => 'Ballpen']);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-SPLIT-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 50,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeA = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'name' => 'Employee A',
        ]);
        $employeeB = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'name' => 'Employee B',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'name' => 'Unit Consolidator',
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        $reqA = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employeeA->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'EMP-A-1',
        ]);
        $lineA = RequisitionItem::query()->create([
            'requisition_id' => $reqA->id,
            'item_id' => $item->id,
            'quantity' => 10,
        ]);

        $reqB = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employeeB->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'EMP-B-1',
        ]);
        $lineB = RequisitionItem::query()->create([
            'requisition_id' => $reqB->id,
            'item_id' => $item->id,
            'quantity' => 8,
        ]);

        $consolidated = Requisition::query()->create([
            'reference_code' => '2026-01-0500',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $consolidatedLine = RequisitionItem::query()->create([
            'requisition_id' => $consolidated->id,
            'item_id' => $item->id,
            'quantity' => 15,
        ]);

        $endorsementA = RequisitionSourceEndorsement::query()->create([
            'consolidated_requisition_id' => $consolidated->id,
            'source_requisition_id' => $reqA->id,
            'requisition_item_id' => $lineA->id,
            'requested_by_user_id' => $employeeA->id,
            'item_id' => $item->id,
            'requested_quantity' => 10,
            'endorsed_quantity' => 8,
        ]);
        $endorsementB = RequisitionSourceEndorsement::query()->create([
            'consolidated_requisition_id' => $consolidated->id,
            'source_requisition_id' => $reqB->id,
            'requisition_item_id' => $lineB->id,
            'requested_by_user_id' => $employeeB->id,
            'item_id' => $item->id,
            'requested_quantity' => 8,
            'endorsed_quantity' => 7,
        ]);

        $result = app(RequisitionFulfillmentService::class)->issueLines(
            $consolidated,
            $custodian,
            [
                ['source_endorsement_id' => $endorsementA->id, 'quantity_to_issue' => 5],
                ['source_endorsement_id' => $endorsementB->id, 'quantity_to_issue' => 6],
            ],
            '2026-06-10',
        );

        $this->assertSame(2, $result['created']);

        $issuanceA = Issuance::query()->where('source_endorsement_id', $endorsementA->id)->first();
        $issuanceB = Issuance::query()->where('source_endorsement_id', $endorsementB->id)->first();

        $this->assertNotNull($issuanceA);
        $this->assertNotNull($issuanceB);
        $this->assertSame($employeeA->id, $issuanceA->issued_to);
        $this->assertSame($employeeB->id, $issuanceB->issued_to);
        $this->assertSame($reqA->id, $issuanceA->requisition_id);
        $this->assertSame($reqB->id, $issuanceB->requisition_id);
        $this->assertSame($consolidated->id, $issuanceA->consolidated_requisition_id);
        $this->assertSame(5, $issuanceA->quantity);
        $this->assertSame(6, $issuanceB->quantity);
        $this->assertSame(11, (int) $consolidatedLine->fresh()->quantity_issued);

        $values = app(OwwaTemplateExportService::class)->cellValuesForIssuance(
            $issuanceA->loadMissing(['office', 'department', 'item', 'requisition', 'consolidatedRequisition.requestedBy', 'batch']),
            'Consumable/Issuances/Appendix 64 - RSMI.xls'
        );

        $this->assertSame($consolidated->reference_code, $values['A12']);

        $paperworkValues = app(OwwaTemplateExportService::class)->cellValuesForIssuance(
            $issuanceA->fresh()->loadMissing(['batch', 'consolidatedRequisition.requestedBy', 'issuedTo']),
            null,
        );

        $this->assertSame($uc->name, $paperworkValues['B8']);
    }

    public function test_semi_expendable_issue_uses_units_stored_at_regional_supply(): void
    {
        $supplyOffice = Office::factory()->create([
            'name' => 'Regional Supply',
            'is_regional_supply' => true,
        ]);
        $requestingOffice = Office::factory()->create([
            'name' => 'Field Office',
            'is_regional_supply' => false,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'property_class' => \App\Support\ItemPropertyClass::OfficeEquipment,
            'estimated_useful_life' => '60 months',
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $requestingOffice->id,
        ]);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-SEMI-SUPPLY',
            'item_id' => $item->id,
            'office_id' => $supplyOffice->id,
            'quantity' => 1,
            'unit_cost' => 1500,
            'acquisition_date' => now()->toDateString(),
            'recorded_by' => $custodian->id,
        ]);

        $unit = InventoryUnit::query()->where('acquisition_id', $acquisition->id)->first();
        $this->assertNotNull($unit);
        $this->assertSame($supplyOffice->id, $unit->office_id);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0600',
            'office_id' => $requestingOffice->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $result = app(RequisitionFulfillmentService::class)->issueLines(
            $requisition,
            $custodian,
            [
                [
                    'requisition_item_id' => $line->id,
                    'quantity_to_issue' => 1,
                ],
            ],
            '2026-06-11',
        );

        $this->assertSame(1, $result['created']);

        $issuance = Issuance::query()->where('requisition_id', $requisition->id)->first();
        $this->assertNotNull($issuance);
        $this->assertSame($unit->property_number, $issuance->property_number);

        $unit->refresh();
        $this->assertSame(InventoryUnit::STATUS_ISSUED, $unit->status);
        $this->assertSame($issuance->id, $unit->issuance_id);
    }

    public function test_issuance_without_requisition_is_blocked(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $this->expectException(\InvalidArgumentException::class);

        Issuance::query()->create([
            'office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'issuance_date' => now()->toDateString(),
        ]);
    }

    public function test_ris_approval_flow_notifies_and_issues_only_after_mark_approved(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Notification::fake();

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-APPROVAL-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 50,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'name' => 'Maria Santos',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'name' => 'Unit Head',
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        $employeeRequest = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'EMP-APPR-1',
        ]);
        $employeeLine = RequisitionItem::query()->create([
            'requisition_id' => $employeeRequest->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-09-0202',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $endorsement = RequisitionSourceEndorsement::query()->create([
            'consolidated_requisition_id' => $requisition->id,
            'source_requisition_id' => $employeeRequest->id,
            'requisition_item_id' => $employeeLine->id,
            'requested_by_user_id' => $employee->id,
            'item_id' => $item->id,
            'requested_quantity' => 2,
            'endorsed_quantity' => 2,
        ]);

        $employeeRequest->update(['compiled_into_requisition_id' => $requisition->id]);

        $this->actingAs($custodian);

        $lines = app(RequisitionFulfillmentService::class)
            ->defaultEndorsementIssueLines($requisition->fresh(), false);

        Livewire::test(ListRequisitions::class)
            ->mountTableAction('view', $requisition)
            ->callAction(TestAction::make('acceptAndIssue'), [
                'issuance_date' => '2026-06-07',
                'ris_requested_by_name' => $uc->name,
                'ris_requested_by_designation' => 'Operations',
                'ris_requested_by_date' => '2026-06-07',
                'ris_approved_by_name' => 'Approving Officer',
                'ris_approved_by_designation' => 'Chief',
                'ris_approved_by_date' => '2026-06-07',
                'ris_issued_by_name' => 'Supply Custodian',
                'ris_issued_by_designation' => 'Supply Officer',
                'ris_issued_by_date' => '2026-06-07',
                'ris_received_by_name' => $employee->name,
                'ris_received_by_designation' => 'Staff',
                'ris_received_by_date' => '2026-06-07',
                'lines' => $lines,
            ])
            ->assertNotified();

        $requisition->refresh();

        $this->assertSame(0, Issuance::query()->count());
        $this->assertSame(Requisition::RIS_APPROVAL_FOR_APPROVAL, $requisition->ris_approval_status);
        $this->assertSame($employee->name, $requisition->ris_received_by_name);

        Notification::assertSentTo(
            $uc,
            RequisitionWorkflowDatabaseNotification::class,
            fn (RequisitionWorkflowDatabaseNotification $notification): bool => $notification->title === 'RIS for approval',
        );
        Notification::assertSentTo(
            $employee,
            RequisitionWorkflowDatabaseNotification::class,
            fn (RequisitionWorkflowDatabaseNotification $notification): bool => $notification->title === 'RIS for approval',
        );
        Notification::assertNotSentTo(
            $custodian,
            RequisitionWorkflowDatabaseNotification::class,
            fn (RequisitionWorkflowDatabaseNotification $notification): bool => $notification->title === 'RIS for approval',
        );

        $values = app(OwwaTemplateExportService::class)->cellValuesForRequisition($requisition->fresh());
        $this->assertSame('2', (string) $values['G12']);

        Livewire::test(ListRequisitions::class)
            ->mountTableAction('view', $requisition)
            ->assertActionHidden(TestAction::make('issueApprovedStock'))
            ->assertActionVisible(TestAction::make('markRisApproved'));

        $this->assertSame(0, Issuance::query()->count());
        $this->assertTrue($requisition->fresh()->isRisForApproval());

        Livewire::test(ListRequisitions::class)
            ->mountTableAction('view', $requisition)
            ->callAction(TestAction::make('markRisApproved'))
            ->assertNotified();

        $this->assertTrue($requisition->fresh()->isRisApprovedReadyToIssue());
        $this->assertSame(0, Issuance::query()->count());

        Livewire::test(ListRequisitions::class)
            ->mountTableAction('view', $requisition)
            ->assertActionVisible(TestAction::make('issueApprovedStock'))
            ->callAction(TestAction::make('issueApprovedStock'))
            ->assertNotified();

        $this->assertSame(1, Issuance::query()->where('source_endorsement_id', $endorsement->id)->count());
        $this->assertNull($requisition->fresh()->ris_approval_status);
        $this->assertNull($requisition->fresh()->pending_issue_payload);
        $this->assertSame(Requisition::STATUS_ACCEPTED, $requisition->fresh()->status);
    }

    public function test_received_by_uses_consolidator_when_multiple_employees_share_ris(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $employeeA = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'name' => 'Employee A',
        ]);
        $employeeB = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'name' => 'Employee B',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'name' => 'Unit Head',
        ]);

        $reqA = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employeeA->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);
        $lineA = RequisitionItem::query()->create([
            'requisition_id' => $reqA->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);
        $reqB = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employeeB->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);
        $lineB = RequisitionItem::query()->create([
            'requisition_id' => $reqB->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $consolidated = Requisition::query()->create([
            'reference_code' => '2026-09-0300',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $consolidated->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        RequisitionSourceEndorsement::query()->create([
            'consolidated_requisition_id' => $consolidated->id,
            'source_requisition_id' => $reqA->id,
            'requisition_item_id' => $lineA->id,
            'requested_by_user_id' => $employeeA->id,
            'item_id' => $item->id,
            'requested_quantity' => 1,
            'endorsed_quantity' => 1,
        ]);
        RequisitionSourceEndorsement::query()->create([
            'consolidated_requisition_id' => $consolidated->id,
            'source_requisition_id' => $reqB->id,
            'requisition_item_id' => $lineB->id,
            'requested_by_user_id' => $employeeB->id,
            'item_id' => $item->id,
            'requested_quantity' => 1,
            'endorsed_quantity' => 1,
        ]);

        $receivedBy = RequisitionIssuanceFormSchema::resolveReceivedByUser($consolidated->fresh());

        $this->assertTrue($receivedBy?->is($uc));
    }

    public function test_issued_by_defaults_to_signed_in_custodian_account_name(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create([
            'supply_custodian_name' => 'Office Default Custodian',
            'supply_custodian_designation' => 'Supply Officer',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'name' => 'Dan Jediael Caranay',
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-09-0500',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $this->actingAs($custodian);

        [$name, $designation] = RequisitionIssuanceFormSchema::resolveIssuedBy($requisition);

        $this->assertSame('Dan Jediael Caranay', $name);
        $this->assertSame('Supply Officer', $designation);

        $state = RequisitionIssuanceFormSchema::defaultFormState($requisition);
        $this->assertSame('Dan Jediael Caranay', $state['ris_issued_by_name']);
        $this->assertSame('Supply Officer', $state['ris_issued_by_designation']);
    }

    public function test_close_remaining_balance_hides_issue_remainder(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-CLOSE-REM',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 50,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
        ]);

        $employeeRequest = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'EMP-CLOSE-1',
        ]);
        $employeeLine = RequisitionItem::query()->create([
            'requisition_id' => $employeeRequest->id,
            'item_id' => $item->id,
            'quantity' => 20,
            'quantity_issued' => 16,
        ]);

        $consolidated = Requisition::query()->create([
            'reference_code' => '2026-09-0400',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $consolidated->id,
            'item_id' => $item->id,
            'quantity' => 20,
            'quantity_issued' => 16,
        ]);

        $endorsement = RequisitionSourceEndorsement::query()->create([
            'consolidated_requisition_id' => $consolidated->id,
            'source_requisition_id' => $employeeRequest->id,
            'requisition_item_id' => $employeeLine->id,
            'requested_by_user_id' => $employee->id,
            'item_id' => $item->id,
            'requested_quantity' => 20,
            'endorsed_quantity' => 20,
        ]);

        $employeeRequest->update(['compiled_into_requisition_id' => $consolidated->id]);

        Issuance::withoutEvents(function () use ($office, $item, $employeeRequest, $consolidated, $endorsement, $employee, $custodian): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-CLOSE-REM',
                'office_id' => $office->id,
                'item_id' => $item->id,
                'quantity' => 16,
                'issuance_date' => now()->toDateString(),
                'requisition_id' => $employeeRequest->id,
                'consolidated_requisition_id' => $consolidated->id,
                'source_endorsement_id' => $endorsement->id,
                'issued_to' => $employee->id,
                'issued_by' => $custodian->id,
            ]);
        });

        $this->actingAs($custodian);

        $this->assertTrue(CustodianRequisitionActions::canIssueRemainder($consolidated->fresh()));
        $this->assertTrue(CustodianRequisitionActions::canCloseRemainingBalance($consolidated->fresh()));

        Livewire::test(ListRequisitions::class)
            ->mountTableAction('view', $consolidated)
            ->callAction(TestAction::make('closeRemainingBalance'))
            ->assertNotified();

        $this->assertNotNull($employeeRequest->fresh()->closed_at);
        $this->assertFalse(CustodianRequisitionActions::canIssueRemainder($consolidated->fresh()));
        $this->assertFalse(CustodianRequisitionActions::canCloseRemainingBalance($consolidated->fresh()));
    }
}
