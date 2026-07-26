<?php

namespace Tests\Feature;

use App\Filament\Resources\UacsObjectCodes\Pages\ListUacsObjectCodes;
use App\Models\UacsObjectCode;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UacsObjectCodeModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_creates_uacs_object_code_from_list_modal(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUacsObjectCodes::class)
            ->callAction(TestAction::make('create')->schemaComponent(true, 'content'), [
                'code' => '10605030',
                'name' => 'Office Equipment',
                'property_class' => null,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(UacsObjectCode::class, [
            'code' => '10605030',
            'name' => 'Office Equipment',
            'is_active' => true,
        ]);
    }

    public function test_system_admin_edits_uacs_object_code_from_table_modal(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $code = UacsObjectCode::query()->create([
            'code' => '10605010',
            'name' => 'Furniture',
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUacsObjectCodes::class)
            ->callTableAction('edit', $code, [
                'code' => '10605010',
                'name' => 'Furniture and fixtures',
                'property_class' => null,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas(UacsObjectCode::class, [
            'id' => $code->id,
            'name' => 'Furniture and fixtures',
            'is_active' => true,
        ]);
    }

    public function test_system_admin_archives_and_restores_uacs_object_code(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $code = UacsObjectCode::query()->create([
            'code' => '10605020',
            'name' => 'IT Equipment',
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUacsObjectCodes::class)
            ->callTableAction('archive', $code)
            ->assertHasNoTableActionErrors();

        $this->assertFalse($code->fresh()->is_active);

        Livewire::test(ListUacsObjectCodes::class)
            ->set('activeTab', 'archived')
            ->callTableAction('unarchive', $code->fresh())
            ->assertHasNoTableActionErrors();

        $this->assertTrue($code->fresh()->is_active);
    }

    public function test_uacs_list_page_uses_wizard_heading_without_create_route(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUacsObjectCodes::class)
            ->assertSuccessful()
            ->assertSeeHtml('owwa-wizard-title');

        $this->get('/system-admin/uacs-object-codes/create')->assertNotFound();
    }
}
