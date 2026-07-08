<?php

namespace Tests\Feature;

use App\Filament\Pages\MyInventory;
use App\Models\Distribution;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\EmployeeDistributionInventoryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_grouped_inventory_merges_same_item_rows(): void
    {
        [$employee, $item] = $this->seedEmployeeDistributions([
            ['quantity' => 5, 'date' => '2026-03-07'],
            ['quantity' => 3, 'date' => '2026-04-05'],
        ]);

        Livewire::actingAs($employee)
            ->test(MyInventory::class)
            ->assertSee('Test Item')
            ->assertSee('8');

        $rows = app(EmployeeDistributionInventoryService::class)
            ->paginatedGroupedInventory($employee, null, 'distribution_date', 'desc');

        $this->assertSame(1, $rows->total());
        $this->assertSame(8, (int) $rows->first()->total_quantity);
        $this->assertSame(2, (int) $rows->first()->distribution_count);
    }

    public function test_summary_kpis_match_merged_totals(): void
    {
        [$employee] = $this->seedEmployeeDistributions([
            ['quantity' => 5, 'date' => '2026-03-07'],
            ['quantity' => 3, 'date' => '2026-04-05'],
        ]);

        $summary = app(EmployeeDistributionInventoryService::class)->summaryFor($employee);

        $this->assertSame(1, $summary['totalItems']);
        $this->assertSame(8, $summary['totalQuantity']);
        $this->assertSame(8, $summary['totalQuantityThisYear']);
    }

    public function test_employee_can_open_distribution_ledger_modal(): void
    {
        [$employee, $item] = $this->seedEmployeeDistributions([
            ['quantity' => 5, 'date' => '2026-03-07'],
        ]);

        Livewire::actingAs($employee)
            ->test(MyInventory::class)
            ->call('openDistributionLedger', $item->id)
            ->assertActionMounted('viewDistributionLedger');
    }

    public function test_ledger_includes_running_balances(): void
    {
        [$employee, $item] = $this->seedEmployeeDistributions([
            ['quantity' => 5, 'date' => '2026-03-07'],
            ['quantity' => 3, 'date' => '2026-04-05'],
        ]);

        $ledger = app(EmployeeDistributionInventoryService::class)->presentLedger($employee, $item->id);

        $balances = collect($ledger['rows'])->pluck('balance')->map(fn ($b) => (int) $b)->all();

        $this->assertContains(5, $balances);
        $this->assertContains(8, $balances);
        $this->assertSame('8', $ledger['header']['total_on_hand']);
    }

    public function test_open_distribution_ledger_rejects_item_not_distributed_to_employee(): void
    {
        [$employee, $item] = $this->seedEmployeeDistributions([
            ['quantity' => 5, 'date' => '2026-03-07'],
        ]);

        $otherItem = Item::factory()->create([
            'item_category_id' => $item->item_category_id,
        ]);

        Livewire::actingAs($employee)
            ->test(MyInventory::class)
            ->call('openDistributionLedger', $otherItem->id)
            ->assertStatus(403);
    }

    public function test_ledger_item_url_param_auto_mounts_modal(): void
    {
        [$employee, $item] = $this->seedEmployeeDistributions([
            ['quantity' => 5, 'date' => '2026-03-07'],
        ]);

        Livewire::actingAs($employee)
            ->test(MyInventory::class, ['ledgerItem' => $item->id])
            ->assertActionMounted('viewDistributionLedger');
    }

    public function test_category_dropdown_filters_inventory_and_kpis(): void
    {
        $office = Office::factory()->create();
        $consumables = ItemCategory::query()->firstOrCreate(
            ['name' => 'Consumables'],
            ['description' => 'Consumables'],
        );
        $ppe = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'PPE'],
        );

        $consumableItem = Item::factory()->create([
            'item_category_id' => $consumables->id,
            'name' => 'Bond Paper',
        ]);
        $ppeItem = Item::factory()->create([
            'item_category_id' => $ppe->id,
            'name' => 'Desktop Computer',
        ]);

        /** @var User $employee */
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        /** @var User $distributor */
        $distributor = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);

        Distribution::factory()->create([
            'office_id' => $office->id,
            'item_id' => $consumableItem->id,
            'distributed_to' => $employee->id,
            'distributed_by' => $distributor->id,
            'quantity' => 5,
            'distribution_date' => '2026-03-07',
        ]);
        Distribution::factory()->create([
            'office_id' => $office->id,
            'item_id' => $ppeItem->id,
            'distributed_to' => $employee->id,
            'distributed_by' => $distributor->id,
            'quantity' => 1,
            'distribution_date' => '2026-03-08',
        ]);

        $service = app(EmployeeDistributionInventoryService::class);

        $this->assertSame(
            ['consumables', 'semi_expendable', 'ppe'],
            array_keys(EmployeeDistributionInventoryService::categoryOptions()),
        );

        $consumableRows = $service->paginatedGroupedInventory($employee, null, 'distribution_date', 'desc', 10, 'consumables');
        $ppeRows = $service->paginatedGroupedInventory($employee, null, 'distribution_date', 'desc', 10, 'ppe');

        $this->assertSame(1, $consumableRows->total());
        $this->assertSame('Bond Paper', $consumableRows->first()->item_name);
        $this->assertSame(1, $ppeRows->total());
        $this->assertSame('Desktop Computer', $ppeRows->first()->item_name);

        $ppeSummary = $service->summaryFor($employee, 'ppe');
        $this->assertSame(1, $ppeSummary['totalItems']);
        $this->assertSame(1, $ppeSummary['totalQuantity']);

        Livewire::actingAs($employee)
            ->test(MyInventory::class)
            ->assertSet('category', 'consumables')
            ->assertSee('Bond Paper')
            ->assertDontSee('Desktop Computer')
            ->set('category', 'ppe')
            ->assertSet('category', 'ppe')
            ->assertSee('Desktop Computer')
            ->assertDontSee('Bond Paper');
    }

    /**
     * @param  array<int, array{quantity: int, date: string}>  $distributions
     * @return array{0: User, 1: Item}
     */
    protected function seedEmployeeDistributions(array $distributions): array
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Test Item',
        ]);

        /** @var User $employee */
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        /** @var User $distributor */
        $distributor = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);

        foreach ($distributions as $distribution) {
            Distribution::factory()->create([
                'office_id' => $office->id,
                'item_id' => $item->id,
                'distributed_to' => $employee->id,
                'distributed_by' => $distributor->id,
                'quantity' => $distribution['quantity'],
                'distribution_date' => $distribution['date'],
            ]);
        }

        return [$employee, $item];
    }
}
