<?php

namespace Tests\Feature;

use App\Filament\Pages\StockLevels;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Support\InventoryCategoryTasks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryCategoryDashboardUcTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_unit_consolidator_cannot_access_stock_levels(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $this->actingAs($uc);

        $this->assertFalse(StockLevels::canAccess());

        Livewire::test(StockLevels::class, ['category' => ItemCategory::factory()->create()->id])
            ->assertForbidden();
    }

    public function test_category_tasks_replace_the_category_dashboard(): void
    {
        $this->assertFileDoesNotExist(app_path('Filament/Pages/InventoryCategoryDashboard.php'));
        $this->assertFileDoesNotExist(resource_path('views/filament/pages/inventory-category-dashboard.blade.php'));

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $titles = array_column(InventoryCategoryTasks::forCategory($category), 'title');

        $this->assertContains('Stock levels', $titles);
        $this->assertContains('Items', $titles);
        $this->assertNotContains('Transfers', $titles);
    }
}
