<?php

namespace Tests\Feature;

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
}
