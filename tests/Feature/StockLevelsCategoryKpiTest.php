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

class StockLevelsCategoryKpiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_semi_expendable_stock_levels_show_useful_life_due_instead_of_low_stock(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Due Chair',
        ]);

        DB::table('issuances')->insert([
            'reference_code' => 'ISS-STOCK-EUL',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'issuance_date' => now()->subYears(4)->toDateString(),
            'estimated_useful_life' => '36 months',
            'eul_expires_at' => now()->subDay()->toDateString(),
            'property_number' => 'SEMI-STOCK-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->assertSee('Useful Life Due')
            ->assertSee('1')
            ->assertDontSee('Low stock');
    }

    public function test_ppe_stock_levels_omit_the_low_stock_card(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'PPE'],
        );
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->assertSee('Total items')
            ->assertSee('In stock')
            ->assertSee('Total stock')
            ->assertDontSee('Low stock')
            ->assertDontSee('Useful Life Due');
    }
}
