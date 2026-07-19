<?php

namespace Tests\Feature;

use App\Filament\Resources\PhysicalCountSessions\Pages\ListPhysicalCountSessions;
use App\Filament\Resources\PhysicalCountSessions\Pages\StartPhysicalCountMobile;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StartPhysicalCountMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_custodian_starts_count_with_assigned_office_without_picking(): void
    {
        $office = Office::factory()->create(['name' => 'OWWA Regional Office IV-A']);
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(StartPhysicalCountMobile::class)
            ->assertSet('officeId', $office->id)
            ->set('itemCategoryId', $category->id)
            ->call('startCount')
            ->assertHasNoErrors()
            ->assertRedirect();

        $session = PhysicalCountSession::query()->latest('id')->first();
        $this->assertNotNull($session);
        $this->assertSame($office->id, $session->office_id);
        $this->assertSame(PhysicalCountSession::TYPE_RPCPPE, $session->count_type);
        $this->assertNull($session->inventory_type_label);
    }

    public function test_custodian_always_uses_assigned_office_even_when_tampered(): void
    {
        $home = Office::factory()->create();
        $other = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $home->id,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(StartPhysicalCountMobile::class)
            ->set('officeId', $other->id)
            ->set('itemCategoryId', $category->id)
            ->call('startCount')
            ->assertHasNoErrors()
            ->assertRedirect();

        $session = PhysicalCountSession::query()->first();
        $this->assertNotNull($session);
        $this->assertSame($home->id, $session->office_id);
    }

    public function test_consumable_category_is_rejected_for_mobile_start(): void
    {
        $office = Office::factory()->create();
        $ppe = ItemCategory::factory()->create(['name' => 'PPE']);
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::withQueryParams(['category' => $ppe->id])
            ->test(StartPhysicalCountMobile::class)
            ->set('itemCategoryId', $consumables->id)
            ->call('startCount')
            ->assertHasErrors(['itemCategoryId']);

        $this->assertSame(0, PhysicalCountSession::query()->count());
    }

    public function test_start_mobile_page_redirects_away_from_consumable_category(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::withQueryParams(['category' => $category->id])
            ->test(StartPhysicalCountMobile::class)
            ->assertRedirect();
    }

    public function test_start_mobile_header_action_hidden_for_consumables(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::withQueryParams(['category' => $category->id])
            ->test(ListPhysicalCountSessions::class)
            ->assertActionHidden('startMobile');
    }

    public function test_start_mobile_header_action_visible_for_ppe(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::withQueryParams(['category' => $category->id])
            ->test(ListPhysicalCountSessions::class)
            ->assertActionVisible('startMobile');
    }
}
