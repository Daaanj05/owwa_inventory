<?php

namespace Tests\Feature;

use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Filament\Resources\Disposals\Pages\ListDisposals;
use App\Filament\Resources\Issuances\Pages\ListIssuances;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Filament\Resources\Transfers\Pages\ListTransfers;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FilamentTaskTableToolbarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: class-string, 1: bool, 2?: string}>
     */
    public static function taskListPageProvider(): array
    {
        return [
            'items' => [ListItems::class, true, 'Consumables'],
            'acquisitions' => [ListAcquisitions::class, true, 'Consumables'],
            'issuances' => [ListIssuances::class, true, 'Consumables'],
            'transfers' => [ListTransfers::class, true, 'Semi-Expendable'],
            'disposals' => [ListDisposals::class, true, 'Consumables'],
            'requisitions' => [ListRequisitions::class, false],
        ];
    }

    #[DataProvider('taskListPageProvider')]
    public function test_task_list_pages_hide_redundant_table_toolbar_icons(
        string $pageClass,
        bool $usesCategory,
        ?string $categoryName = null,
    ): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        if ($usesCategory) {
            $category = ItemCategory::factory()->create(['name' => $categoryName ?? 'Consumables']);
            session(['active_item_category_id' => $category->id]);

            $component = Livewire::actingAs($custodian)
                ->withQueryParams(['category' => (string) $category->id])
                ->test($pageClass);
        } else {
            $component = Livewire::actingAs($custodian)
                ->test($pageClass);
        }

        $component->assertSuccessful();

        $table = $component->instance()->getTable();

        $this->assertTrue($table->getFiltersTriggerAction()->isHidden());
        $this->assertFalse($table->hasColumnManager());
    }
}
