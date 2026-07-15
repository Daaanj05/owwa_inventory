<?php

namespace Tests\Feature;

use App\Filament\Pages\MyStockLevels;
use App\Filament\Pages\StockLevels;
use App\Filament\Widgets\EmployeeStockLevelsWidget;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyStockLevelsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_employees_cannot_access_my_stock_levels(): void
    {
        /** @var User $employee */
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->assertFalse(MyStockLevels::canAccess());
        $this->assertFalse(MyStockLevels::shouldRegisterNavigation());

        $this->actingAs($employee);

        $this->assertFalse(MyStockLevels::canAccess());
        $this->assertFalse(EmployeeStockLevelsWidget::canView());

        Livewire::actingAs($employee)
            ->test(MyStockLevels::class)
            ->assertForbidden();
    }

    public function test_unit_consolidators_cannot_access_stock_levels(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $this->actingAs($uc);

        $this->assertFalse(StockLevels::canAccess());
    }
}
