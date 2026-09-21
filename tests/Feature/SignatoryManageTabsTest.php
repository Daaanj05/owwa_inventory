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
            ->assertSet('activeTab', 'pr_iar');
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
                'role' => ProcurementSignatoryName::ROLE_TRANSFER_RELEASED,
                'name' => 'Released Officer',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(ProcurementSignatoryName::class, [
            'role' => ProcurementSignatoryName::ROLE_TRANSFER_RELEASED,
            'name' => 'Released Officer',
        ]);
    }

    public function test_role_options_for_tab_are_scoped(): void
    {
        $transferOptions = SignatorySelect::roleOptionsForTab('transfer');
        $this->assertArrayHasKey(ProcurementSignatoryName::ROLE_TRANSFER_APPROVED, $transferOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_REQUESTED, $transferOptions);
        $this->assertArrayNotHasKey(ProcurementSignatoryName::ROLE_DISPOSAL_WITNESS, $transferOptions);
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
