<?php

namespace Tests\Feature;

use App\Filament\Pages\StockLevels;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class StockLedgerModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_custodian_can_open_stock_ledger_modal_for_visible_item(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-MODAL-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 12,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->call('openStockLedger', $item->id, $office->id)
            ->assertActionMounted('viewStockLedger');
    }

    public function test_open_stock_ledger_rejects_item_not_in_visible_list(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $visibleItem = Item::factory()->create(['item_category_id' => $category->id]);
        $hiddenItem = Item::factory()->create(['item_category_id' => $category->id]);

        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-MODAL-VISIBLE',
            'item_id' => $visibleItem->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->call('openStockLedger', $hiddenItem->id, $office->id)
            ->assertStatus(403);
    }
}
