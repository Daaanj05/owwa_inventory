<?php

namespace Tests\Unit;

use App\Filament\Resources\UserLogs\Pages\ListUserLogs;
use App\Models\AiProcurementRun;
use App\Models\DeliveryTerm;
use App\Models\Department;
use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemAttributeOption;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Supplier;
use App\Models\SupplierAddress;
use App\Models\UsefulLifeExtension;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserLog;
use App\Services\UserActivityLogger;
use App\Support\OwwaTransactionViewPresenter;
use App\Support\UserActivityLogDisplay;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserActivityLogDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_requisition_summary_uses_transaction_number(): void
    {
        [$office, $department, $employee] = $this->employeeContext();

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => 'Office supplies',
        ]);

        $activity = $this->activityFor($requisition, 'updated', [
            'changed' => ['status'],
            'old' => ['status' => 'pending'],
            'new' => ['status' => 'accepted'],
        ]);

        $summary = UserActivityLogDisplay::summary($activity);

        $this->assertNotNull($requisition->transaction_number);
        $this->assertNull($requisition->reference_code);
        $this->assertStringContainsString((string) $requisition->transaction_number, $summary);
        $this->assertStringNotContainsString('#'.$requisition->id, $summary);

        $details = collect(UserActivityLogDisplay::details($activity));
        $this->assertSame((string) $requisition->transaction_number, $details->firstWhere('label', 'Transaction no.')['value'] ?? null);
        $this->assertSame($office->name, $details->firstWhere('label', 'Office')['value'] ?? null);
        $this->assertSame($department->name, $details->firstWhere('label', 'Sub-Office/Department')['value'] ?? null);
        $this->assertStringContainsString('Pending → Accepted', (string) ($details->firstWhere('label', 'What changed')['value'] ?? ''));
    }

    public function test_ris_summary_uses_reference_code(): void
    {
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => 'Compiled slip',
        ]);

        $summary = UserActivityLogDisplay::summary($this->activityFor($requisition, 'created'));

        $this->assertNotNull($requisition->reference_code);
        $this->assertStringContainsString((string) $requisition->reference_code, $summary);
        $this->assertStringNotContainsString('#'.$requisition->id, $summary);
    }

    public function test_requisition_item_summary_names_the_item_and_parent_number(): void
    {
        [$office, $department, $employee] = $this->employeeContext();

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => 'Bond paper',
        ]);

        $item = Item::factory()->create([
            'name' => 'Bond Paper',
            'base_name' => 'Bond Paper',
            'sub_item' => null,
            'item_code' => 'ITM-BOND',
        ]);

        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 5,
        ]);

        $summary = UserActivityLogDisplay::summary($this->activityFor($line, 'created'));

        $this->assertStringContainsString('Bond Paper × 5', $summary);
        $this->assertStringContainsString((string) $requisition->transaction_number, $summary);
        $this->assertStringNotContainsString('#'.$line->id, $summary);
    }

    public function test_missing_subject_keeps_the_stored_summary(): void
    {
        $activity = UserActivityLog::query()->make([
            'action' => 'updated',
            'summary' => 'Updated Requisition #17',
            'subject_type' => Requisition::class,
            'subject_id' => 999999,
        ]);

        $this->assertSame('Updated Requisition #17', UserActivityLogDisplay::summary($activity));
        $this->assertSame('Updated Requisition #17', UserActivityLogDisplay::details($activity)[0]['value'] ?? null);
    }

    public function test_audit_log_table_and_session_rows_show_assignment_and_specific_summary(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        [$office, $department, $employee] = $this->employeeContext();
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => 'Session supplies',
        ]);
        $item = Item::factory()->create([
            'name' => 'Bond Paper',
            'base_name' => 'Bond Paper',
            'sub_item' => null,
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $log = UserLog::query()->create([
            'user_id' => $employee->id,
            'ip_address' => '127.0.0.1',
            'logged_in_at' => now()->subMinutes(10),
            'last_activity_at' => now(),
        ]);
        UserActivityLog::query()->create([
            'user_id' => $employee->id,
            'user_log_id' => $log->id,
            'action' => 'created',
            'summary' => 'Created Requisition Item #'.$line->id,
            'subject_type' => $line->getMorphClass(),
            'subject_id' => $line->id,
        ]);

        $this->actingAs($admin);

        $page = Livewire::test(ListUserLogs::class)
            ->assertCanSeeTableRecords([$log])
            ->assertSee('Employee')
            ->assertSee('Logged In At')
            ->assertSee('Logged Out At')
            ->assertSee('Actions')
            ->assertDontSee('Regional Welfare Office')
            ->assertDontSee('Welfare Programs')
            ->assertDontSee('Logout Reason')
            ->assertDontSee('IP Address')
            ->assertDontSee('Panel');

        $hero = OwwaTransactionViewPresenter::forUserLog($log);
        $meta = collect($hero['meta'] ?? []);
        $this->assertSame('Employee', $meta->firstWhere('label', 'Role')['value'] ?? null);
        $this->assertSame('Regional Welfare Office', $meta->firstWhere('label', 'Office')['value'] ?? null);
        $this->assertSame('Welfare Programs', $meta->firstWhere('label', 'Sub-Office/Department')['value'] ?? null);

        $html = view('filament.partials.user-log-session-activities', ['record' => $log])->render();
        $this->assertStringContainsString('Bond Paper × 2', $html);
        $this->assertStringContainsString((string) $requisition->transaction_number, $html);
        $this->assertStringContainsString("mountAction('activityDetail'", $html);
        $this->assertStringNotContainsString('Created Requisition Item #'.$line->id, $html);
        $this->assertStringNotContainsString('Welfare Programs', $html);
        $this->assertStringNotContainsString('Requisition item', $html);

        $activity = UserActivityLog::query()->where('user_log_id', $log->id)->first();
        $this->assertNotNull($activity);

        $page->mountAction([
            TestAction::make('view')->table($log),
            TestAction::make('activityDetail')->arguments(['activityId' => $activity->id]),
        ])
            ->assertActionMounted([
                TestAction::make('view')->table($log),
                TestAction::make('activityDetail')->arguments(['activityId' => $activity->id]),
            ])
            ->assertMountedActionModalSee('Requisition item')
            ->assertMountedActionModalSee('Bond Paper');
    }

    public function test_logger_stores_the_transaction_number(): void
    {
        [$office, $department, $employee] = $this->employeeContext();
        $this->actingAs($employee);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => 'Logged create',
        ]);

        $stored = UserActivityLog::query()
            ->where('subject_type', $requisition->getMorphClass())
            ->where('subject_id', $requisition->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($stored);
        $this->assertStringContainsString((string) $requisition->transaction_number, (string) $stored->summary);

        $again = app(UserActivityLogger::class)->recordModelEvent($requisition, 'approved');
        $this->assertStringContainsString((string) $requisition->transaction_number, (string) $again?->summary);
    }

    public function test_other_logged_records_use_a_specific_summary_and_details(): void
    {
        $item = new Item(['name' => 'Bond Paper', 'item_code' => 'ITM-BOND']);
        $office = new Office(['name' => 'Regional Welfare Office']);
        $requisition = new Requisition(['transaction_number' => 'TX-2026-0007']);
        $requisition->setRelation('office', $office);

        $distribution = new Distribution(['quantity' => 3, 'remarks' => 'Issued to staff']);
        $distribution->setRelation('item', $item);
        $distribution->setRelation('requisition', $requisition);
        $distribution->setRelation('office', $office);
        $distribution->setRelation('department', null);
        $distribution->setRelation('distributedTo', null);

        $distributionSummary = UserActivityLogDisplay::summary($this->activityOn($distribution, 'created'));
        $this->assertStringContainsString('Bond Paper × 3', $distributionSummary);
        $this->assertStringContainsString('TX-2026-0007', $distributionSummary);
        $this->assertSame('Issued to staff', collect(UserActivityLogDisplay::details($this->activityOn($distribution, 'created')))->firstWhere('label', 'Remarks')['value'] ?? null);

        $issuance = new Issuance(['reference_code' => 'ICS-2026-0004', 'quantity' => 1]);
        $issuance->setRelation('item', $item);
        $issuance->setRelation('batch', null);
        $issuance->setRelation('office', $office);
        $issuance->setRelation('department', null);
        $issuance->setRelation('requisition', null);
        $issuance->setRelation('issuedTo', null);
        $extension = new UsefulLifeExtension([
            'previous_eul' => 3,
            'new_eul' => 5,
            'reason' => 'Still serviceable',
        ]);
        $extension->setRelation('issuance', $issuance);
        $extension->setRelation('approvedBy', null);

        $lifeSummary = UserActivityLogDisplay::summary($this->activityOn($extension, 'created'));
        $this->assertStringContainsString('Bond Paper', $lifeSummary);
        $this->assertStringContainsString('ICS-2026-0004', $lifeSummary);
        $this->assertStringContainsString('3 → 5 yr', $lifeSummary);
        $this->assertSame('Still serviceable', collect(UserActivityLogDisplay::details($this->activityOn($extension, 'created')))->firstWhere('label', 'Reason')['value'] ?? null);

        $option = new ItemAttributeOption([
            'kind' => ItemAttributeOption::KIND_UNIT,
            'value' => 'ream',
            'label' => 'Ream',
            'is_active' => true,
        ]);
        $this->assertStringContainsString('Ream', UserActivityLogDisplay::summary($this->activityOn($option, 'created')));
        $this->assertSame('Yes', collect(UserActivityLogDisplay::details($this->activityOn($option, 'created')))->firstWhere('label', 'Active')['value'] ?? null);

        $address = new SupplierAddress(['address' => 'Calamba, Laguna', 'is_default' => true]);
        $address->setRelation('supplier', new Supplier(['name' => 'National Bookstore']));
        $this->assertStringContainsString('National Bookstore', UserActivityLogDisplay::summary($this->activityOn($address, 'updated')));
        $this->assertSame('Calamba, Laguna', collect(UserActivityLogDisplay::details($this->activityOn($address, 'updated')))->firstWhere('label', 'Address')['value'] ?? null);

        $run = new AiProcurementRun([
            'period_from' => '2026-01-01',
            'period_to' => '2026-03-31',
            'status' => 'completed',
            'summary' => 'Reorder bond paper',
        ]);
        $run->setRelation('creator', null);
        $runSummary = UserActivityLogDisplay::summary($this->activityOn($run, 'created'));
        $this->assertStringNotContainsString('#', $runSummary);
        $this->assertSame('Reorder bond paper', collect(UserActivityLogDisplay::details($this->activityOn($run, 'created')))->firstWhere('label', 'Summary')['value'] ?? null);

        $term = new DeliveryTerm(['label' => '30 days', 'is_active' => true]);
        $this->assertStringContainsString('30 days', UserActivityLogDisplay::summary($this->activityOn($term, 'created')));

        $issuanceDetails = collect(UserActivityLogDisplay::details($this->activityOn($issuance, 'created')));
        $this->assertSame('ICS-2026-0004', $issuanceDetails->firstWhere('label', 'Control no.')['value'] ?? null);
        $this->assertSame('Bond Paper (ITM-BOND)', $issuanceDetails->firstWhere('label', 'Item')['value'] ?? null);

        $export = UserActivityLog::query()->make([
            'action' => 'exported',
            'summary' => 'Exported bulk stock cards',
            'properties' => ['count' => 12],
        ]);
        $exportDetails = collect(UserActivityLogDisplay::details($export));
        $this->assertSame('Exported bulk stock cards', $exportDetails->firstWhere('label', 'Summary')['value'] ?? null);
        $this->assertSame('12', $exportDetails->firstWhere('label', 'Count')['value'] ?? null);
    }

    /**
     * @return array{0: Office, 1: Department, 2: User}
     */
    protected function employeeContext(): array
    {
        $office = Office::factory()->create(['name' => 'Regional Welfare Office']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Welfare Programs',
            'code' => 'WEL',
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'name' => 'Jerome Caya',
        ]);

        return [$office, $department, $employee];
    }

    /**
     * @param  array<string, mixed>|null  $properties
     */
    protected function activityFor(Requisition|RequisitionItem $subject, string $action, ?array $properties = null): UserActivityLog
    {
        $activity = UserActivityLog::query()->make([
            'action' => $action,
            'summary' => 'Updated Requisition #'.$subject->getKey(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'properties' => $properties,
        ]);
        $activity->setRelation('subject', $subject);

        return $activity;
    }

    protected function activityOn(object $subject, string $action): UserActivityLog
    {
        $activity = UserActivityLog::query()->make([
            'action' => $action,
            'summary' => ucfirst($action).' record #99',
            'subject_type' => $subject instanceof \Illuminate\Database\Eloquent\Model ? $subject->getMorphClass() : null,
            'subject_id' => $subject instanceof \Illuminate\Database\Eloquent\Model ? $subject->getKey() : null,
        ]);

        if ($subject instanceof \Illuminate\Database\Eloquent\Model) {
            $activity->setRelation('subject', $subject);
        }

        return $activity;
    }
}
