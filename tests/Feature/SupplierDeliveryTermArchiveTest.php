<?php

namespace Tests\Feature;

use App\Filament\Resources\DeliveryTerms\Pages\ManageDeliveryTerms;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\DeliveryTerm;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierDeliveryTermArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_archive_hides_from_default_table_and_active_options(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $supplier = Supplier::query()->create([
            'name' => 'Archive Supplier',
            'tin' => '111222333',
        ]);

        Livewire::test(ManageSuppliers::class)
            ->callAction(TestAction::make('archive')->table($supplier))
            ->assertNotified()
            ->assertActionDoesNotExist(TestAction::make('delete')->table($supplier));

        $supplier->refresh();
        $this->assertTrue($supplier->isArchived());
        $this->assertNotContains('Archive Supplier', Supplier::nameSuggestions());
        $this->assertFalse(
            Supplier::query()->active()->whereKey($supplier->id)->exists()
        );

        Livewire::test(ManageSuppliers::class)
            ->assertCanNotSeeTableRecords([$supplier]);
    }

    public function test_supplier_restore_and_remember_unarchive(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $supplier = Supplier::query()->create([
            'name' => 'Restore Supplier',
            'tin' => '444555666',
            'archived_at' => now(),
        ]);

        Livewire::test(ManageSuppliers::class)
            ->set('showingArchived', true)
            ->callAction(TestAction::make('restore')->table($supplier))
            ->assertNotified();

        $supplier->refresh();
        $this->assertFalse($supplier->isArchived());
        $this->assertContains('Restore Supplier', Supplier::nameSuggestions());

        $supplier->archive();
        Supplier::remember('Restore Supplier');
        $supplier->refresh();
        $this->assertFalse($supplier->isArchived());
    }

    public function test_supplier_archive_view_toggle(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $active = Supplier::query()->create(['name' => 'Active Co']);
        $archived = Supplier::query()->create([
            'name' => 'Archived Co',
            'archived_at' => now(),
        ]);

        Livewire::test(ManageSuppliers::class)
            ->assertSet('showingArchived', false)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$archived])
            ->assertActionVisible('create')
            ->set('showingArchived', true)
            ->assertCanSeeTableRecords([$archived])
            ->assertCanNotSeeTableRecords([$active])
            ->assertActionHidden('create');
    }

    public function test_delivery_term_archive_hides_from_options_and_table(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $term = DeliveryTerm::query()->create([
            'label' => 'FOB Destination',
            'is_active' => true,
        ]);

        Livewire::test(ManageDeliveryTerms::class)
            ->callAction(TestAction::make('archive')->table($term))
            ->assertNotified()
            ->assertActionDoesNotExist(TestAction::make('delete')->table($term));

        $term->refresh();
        $this->assertTrue($term->isArchived());
        $this->assertFalse($term->is_active);
        $this->assertArrayNotHasKey('FOB Destination', DeliveryTerm::options());

        Livewire::test(ManageDeliveryTerms::class)
            ->assertCanNotSeeTableRecords([$term]);
    }

    public function test_delivery_term_restore_and_remember_unarchive(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $term = DeliveryTerm::query()->create([
            'label' => 'Ex Works',
            'is_active' => false,
            'archived_at' => now(),
        ]);

        Livewire::test(ManageDeliveryTerms::class)
            ->set('showingArchived', true)
            ->callAction(TestAction::make('restore')->table($term))
            ->assertNotified();

        $term->refresh();
        $this->assertFalse($term->isArchived());
        $this->assertTrue($term->is_active);
        $this->assertArrayHasKey('Ex Works', DeliveryTerm::options());

        $term->archive();
        DeliveryTerm::remember('Ex Works');
        $term->refresh();
        $this->assertFalse($term->isArchived());
        $this->assertTrue($term->is_active);
    }

    public function test_delivery_term_archive_view_toggle(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $active = DeliveryTerm::query()->create([
            'label' => 'Active Term',
            'is_active' => true,
        ]);
        $archived = DeliveryTerm::query()->create([
            'label' => 'Archived Term',
            'is_active' => false,
            'archived_at' => now(),
        ]);

        Livewire::test(ManageDeliveryTerms::class)
            ->assertSet('showingArchived', false)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$archived])
            ->assertActionVisible('create')
            ->set('showingArchived', true)
            ->assertCanSeeTableRecords([$archived])
            ->assertCanNotSeeTableRecords([$active])
            ->assertActionHidden('create');
    }
}
