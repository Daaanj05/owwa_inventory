<?php

namespace Tests\Feature;

use App\Filament\Pages\OfficePropertyRegister;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Transfer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OfficePropertyRegisterTransfersTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_transfers_tab_lists_incoming_and_outgoing_with_from_to_offices(): void
    {
        $home = Office::factory()->create(['name' => 'Home Office']);
        $other = Office::factory()->create(['name' => 'Other Office']);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $home->id,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Filing Cabinet',
        ]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        Transfer::withoutEvents(function () use ($home, $other, $item, $custodian): void {
            Transfer::query()->create([
                'reference_code' => 'PTR-IN-1',
                'item_id' => $item->id,
                'from_office_id' => $other->id,
                'to_office_id' => $home->id,
                'quantity' => 1,
                'transfer_date' => now()->toDateString(),
                'recorded_by' => $custodian->id,
            ]);
            Transfer::query()->create([
                'reference_code' => 'PTR-OUT-1',
                'item_id' => $item->id,
                'from_office_id' => $home->id,
                'to_office_id' => $other->id,
                'quantity' => 2,
                'transfer_date' => now()->toDateString(),
                'recorded_by' => $custodian->id,
            ]);
        });

        Livewire::actingAs($uc)
            ->test(OfficePropertyRegister::class, [
                'category' => $category->id,
                'tab' => OfficePropertyRegister::TAB_TRANSFERS,
            ])
            ->assertSee('Transfers')
            ->assertSee('PTR-IN-1')
            ->assertSee('PTR-OUT-1')
            ->assertSee('Incoming')
            ->assertSee('Outgoing')
            ->assertSee('Home Office')
            ->assertSee('Other Office')
            ->set('direction', 'incoming')
            ->assertSee('PTR-IN-1')
            ->assertDontSee('PTR-OUT-1')
            ->set('direction', 'all')
            ->call('openTransfer', Transfer::query()->where('reference_code', 'PTR-OUT-1')->value('id'))
            ->assertActionMounted('viewOfficeTransfer');
    }
}
