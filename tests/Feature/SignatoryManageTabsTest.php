<?php

namespace Tests\Feature;

use App\Filament\Resources\ProcurementSignatoryNames\Pages\ManageProcurementSignatoryNames;
use App\Models\ProcurementSignatoryName;
use App\Models\User;
use App\Support\SignatorySelect;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SignatoryManageTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_tab_is_pr_iar_and_all_is_removed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $this->assertArrayNotHasKey('all', SignatorySelect::tabLabels());

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->assertSet('activeTab', 'pr_iar')
            ->assertSeeHtml('owwa-signatory-form-tabs')
            ->assertSee('PR / IAR')
            ->assertSee('Transfer')
            ->assertSee('Physical count')
            ->assertSee('Disposal');
    }

    public function test_transfer_tab_only_shows_transfer_signatories(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $pr = ProcurementSignatoryName::query()->create([
            'role' => ProcurementSignatoryName::ROLE_REQUESTED,
            'name' => 'PR Person',
        ]);
        $transfer = ProcurementSignatoryName::query()->create([
            'role' => ProcurementSignatoryName::ROLE_TRANSFER_APPROVED,
            'name' => 'Transfer Person',
        ]);
        $disposal = ProcurementSignatoryName::query()->create([
            'role' => ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS,
            'name' => 'Disposal Person',
        ]);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'transfer')
            ->assertCanSeeTableRecords([$transfer])
            ->assertCanNotSeeTableRecords([$pr, $disposal]);
    }

    public function test_create_on_transfer_tab_rejects_pr_role(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'transfer')
            ->callAction('create', [
                'form' => 'transfer',
                'role' => ProcurementSignatoryName::ROLE_REQUESTED,
                'name' => 'Should Fail',
            ])
            ->assertHasFormErrors(['role']);

        $this->assertDatabaseMissing(ProcurementSignatoryName::class, [
            'name' => 'Should Fail',
        ]);
    }

    public function test_create_on_transfer_tab_accepts_transfer_role(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'transfer')
            ->callAction('create', [
                'form' => 'transfer',
                'role' => ProcurementSignatoryName::ROLE_TRANSFER_RELEASED,
                'name' => 'Released Officer',
                'designation' => 'Supply Officer',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(ProcurementSignatoryName::class, [
            'role' => ProcurementSignatoryName::ROLE_TRANSFER_RELEASED,
            'name' => 'Released Officer',
            'designation' => 'Supply Officer',
        ]);
    }

    public function test_pr_role_can_be_saved_from_the_transfer_page_tab(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'transfer')
            ->callAction('create', [
                'form' => 'pr_iar',
                'role' => ProcurementSignatoryName::ROLE_REQUESTED,
                'name' => 'Requester From Transfer Tab',
                'designation' => 'Supply Officer',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(ProcurementSignatoryName::class, [
            'role' => ProcurementSignatoryName::ROLE_REQUESTED,
            'name' => 'Requester From Transfer Tab',
            'designation' => 'Supply Officer',
        ]);
        $this->assertSame('pr_iar', SignatorySelect::tabForRole(ProcurementSignatoryName::ROLE_REQUESTED));
    }

    public function test_role_options_for_tab_are_scoped(): void
    {
        $transferOptions = SignatorySelect::roleOptionsForTab('transfer');
        $this->assertArrayHasKey(ProcurementSignatoryName::ROLE_TRANSFER_APPROVED, $transferOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_REQUESTED, $transferOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS, $transferOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_TRANSFER_FROM_ACCOUNTABLE, $transferOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_TRANSFER_TO_ACCOUNTABLE, $transferOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_TRANSFER_APPROVED_DESIGNATION, $transferOptions);

        $prOptions = SignatorySelect::roleOptionsForTab('pr_iar');
        $this->assertArrayHasKey(ProcurementSignatoryName::ROLE_REQUESTED, $prOptions);
        $this->assertArrayHasKey(ProcurementSignatoryName::ROLE_APPROVED, $prOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_REQUESTED_DESIGNATION, $prOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_APPROVED_DESIGNATION, $prOptions);

        $disposalOptions = SignatorySelect::roleOptionsForTab('disposal');
        $this->assertSame(
            'Disposal — Accountable officer',
            $disposalOptions[ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_OFFICER] ?? null,
        );
        $this->assertSame(
            'Disposal — Authorized official',
            $disposalOptions[ProcurementSignatoryName::ROLE_DISPOSAL_AUTHORIZED_OFFICIAL] ?? null,
        );
        $this->assertSame(
            'Disposal — Inspection officer name',
            $disposalOptions[ProcurementSignatoryName::ROLE_DISPOSAL_INSPECTION_OFFICER] ?? null,
        );
        $this->assertArrayHasKey(ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS, $disposalOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_DESIGNATION, $disposalOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_DISPOSAL_AUTHORIZED_DESIGNATION, $disposalOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_STATION, $disposalOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_DISPOSAL_INSPECTION_OFFICER, $prOptions);

        $physicalCountOptions = SignatorySelect::roleOptionsForTab('physical_count');
        $this->assertArrayHasKey(ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE, $physicalCountOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE_DESIGNATION, $physicalCountOptions);
    }

    public function test_disposal_people_require_designation_only_when_the_sheet_prints_one(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'disposal')
            ->callAction('create', [
                'form' => 'disposal',
                'role' => ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_OFFICER,
                'name' => 'Accountable Without Title',
            ])
            ->assertHasFormErrors(['designation' => 'required']);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'disposal')
            ->callAction('create', [
                'form' => 'disposal',
                'role' => ProcurementSignatoryName::ROLE_DISPOSAL_AUTHORIZED_OFFICIAL,
                'name' => 'Official Without Title',
            ])
            ->assertHasFormErrors(['designation' => 'required']);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'disposal')
            ->callAction('create', [
                'form' => 'disposal',
                'role' => ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_OFFICER,
                'name' => 'Accountable Officer',
                'designation' => 'Supply Officer',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(ProcurementSignatoryName::class, [
            'role' => ProcurementSignatoryName::ROLE_DISPOSAL_ACCOUNTABLE_OFFICER,
            'name' => 'Accountable Officer',
            'designation' => 'Supply Officer',
        ]);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'disposal')
            ->callAction('create', [
                'form' => 'disposal',
                'role' => ProcurementSignatoryName::ROLE_DISPOSAL_AUTHORIZED_OFFICIAL,
                'name' => 'Authorized Official',
                'designation' => 'Regional Director',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(ProcurementSignatoryName::class, [
            'role' => ProcurementSignatoryName::ROLE_DISPOSAL_AUTHORIZED_OFFICIAL,
            'name' => 'Authorized Official',
            'designation' => 'Regional Director',
        ]);
    }

    public function test_physical_count_accountable_officer_requires_designation(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'physical_count')
            ->callAction('create', [
                'form' => 'physical_count',
                'role' => ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE,
                'name' => 'Count Officer Without Title',
            ])
            ->assertHasFormErrors(['designation' => 'required']);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'physical_count')
            ->callAction('create', [
                'form' => 'physical_count',
                'role' => ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_CERTIFIED,
                'name' => 'Committee Chair',
                'designation' => 'Should Clear',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(ProcurementSignatoryName::class, [
            'role' => ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_CERTIFIED,
            'name' => 'Committee Chair',
            'designation' => null,
        ]);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'physical_count')
            ->callAction('create', [
                'form' => 'physical_count',
                'role' => ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE,
                'name' => 'Count Officer',
                'designation' => 'Supply Officer',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(ProcurementSignatoryName::class, [
            'role' => ProcurementSignatoryName::ROLE_PHYSICAL_COUNT_ACCOUNTABLE,
            'name' => 'Count Officer',
            'designation' => 'Supply Officer',
        ]);
    }

    public function test_pr_role_requires_designation(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'pr_iar')
            ->callAction('create', [
                'form' => 'pr_iar',
                'role' => ProcurementSignatoryName::ROLE_REQUESTED,
                'name' => 'Requester Without Title',
            ])
            ->assertHasFormErrors(['designation' => 'required']);

        $this->assertDatabaseMissing(ProcurementSignatoryName::class, [
            'name' => 'Requester Without Title',
        ]);
    }

    public function test_witness_and_iar_inspection_officer_clear_designation(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'pr_iar')
            ->callAction('create', [
                'form' => 'pr_iar',
                'role' => ProcurementSignatoryName::ROLE_INSPECTION_OFFICER,
                'name' => 'IAR Inspector',
                'designation' => 'Should Clear',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(ProcurementSignatoryName::class, [
            'role' => ProcurementSignatoryName::ROLE_INSPECTION_OFFICER,
            'name' => 'IAR Inspector',
            'designation' => null,
        ]);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'disposal')
            ->callAction('create', [
                'form' => 'disposal',
                'role' => ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS,
                'name' => 'Witness Person',
                'designation' => 'Should Clear Too',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(ProcurementSignatoryName::class, [
            'role' => ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS,
            'name' => 'Witness Person',
            'designation' => null,
        ]);
    }

    public function test_disposal_tab_saves_inspection_officer_name_without_designation(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $instruction = SignatorySelect::roleInstruction(ProcurementSignatoryName::ROLE_DISPOSAL_INSPECTION_OFFICER);
        $this->assertNotNull($instruction);
        $this->assertStringNotContainsStringIgnoringCase('printed name', $instruction);
        $this->assertStringNotContainsStringIgnoringCase('signature', $instruction);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'disposal')
            ->callAction('create', [
                'form' => 'disposal',
                'role' => ProcurementSignatoryName::ROLE_DISPOSAL_INSPECTION_OFFICER,
                'name' => 'Disposal Inspector',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(ProcurementSignatoryName::class, [
            'role' => ProcurementSignatoryName::ROLE_DISPOSAL_INSPECTION_OFFICER,
            'name' => 'Disposal Inspector',
            'designation' => null,
        ]);
    }

    public function test_archive_hides_from_suggestions_and_default_table(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $signatory = ProcurementSignatoryName::query()->create([
            'role' => ProcurementSignatoryName::ROLE_TRANSFER_APPROVED,
            'name' => 'Archive Me',
        ]);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'transfer')
            ->callAction(TestAction::make('archive')->table($signatory))
            ->assertNotified();

        $signatory->refresh();
        $this->assertTrue($signatory->isArchived());
        $this->assertNotContains('Archive Me', ProcurementSignatoryName::suggestionsForRole(
            ProcurementSignatoryName::ROLE_TRANSFER_APPROVED,
        ));

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'transfer')
            ->assertCanNotSeeTableRecords([$signatory]);
    }

    public function test_restore_brings_signatory_back_and_remember_unarchives(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $signatory = ProcurementSignatoryName::query()->create([
            'role' => ProcurementSignatoryName::ROLE_TRANSFER_APPROVED,
            'name' => 'Restore Me',
            'archived_at' => now(),
        ]);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'transfer')
            ->set('showingArchived', true)
            ->callAction(TestAction::make('restore')->table($signatory))
            ->assertNotified();

        $signatory->refresh();
        $this->assertFalse($signatory->isArchived());
        $this->assertContains('Restore Me', ProcurementSignatoryName::suggestionsForRole(
            ProcurementSignatoryName::ROLE_TRANSFER_APPROVED,
        ));

        $signatory->archive();
        ProcurementSignatoryName::remember(
            ProcurementSignatoryName::ROLE_TRANSFER_APPROVED,
            'Restore Me',
        );
        $signatory->refresh();
        $this->assertFalse($signatory->isArchived());
    }

    public function test_archive_view_icon_toggles_archived_records(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $active = ProcurementSignatoryName::query()->create([
            'role' => ProcurementSignatoryName::ROLE_TRANSFER_APPROVED,
            'name' => 'Active Officer',
        ]);
        $archived = ProcurementSignatoryName::query()->create([
            'role' => ProcurementSignatoryName::ROLE_TRANSFER_RELEASED,
            'name' => 'Archived Officer',
            'archived_at' => now(),
        ]);

        Livewire::test(ManageProcurementSignatoryNames::class)
            ->set('activeTab', 'transfer')
            ->assertSet('showingArchived', false)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$archived])
            ->assertActionVisible('create')
            ->set('showingArchived', true)
            ->assertCanSeeTableRecords([$archived])
            ->assertCanNotSeeTableRecords([$active])
            ->assertActionHidden('create')
            ->set('showingArchived', false)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$archived]);
    }
}
