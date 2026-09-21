<?php

namespace Tests\Feature;

use App\Filament\Pages\ScanAsset;
use App\Filament\Resources\Disposals\Pages\ListDisposals;
use App\Filament\Resources\IncidentReports\Pages\ListIncidentReports;
use App\Filament\Resources\PropertyActionRequests\Pages\ListPropertyActionRequests;
use App\Filament\Resources\Transfers\Pages\ListTransfers;
use App\Models\Acquisition;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Support\InventoryUnitQrPayload;
use App\Support\ScanAssetHandoff;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScanAssetHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_scan_shows_result_instead_of_redirecting(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, $category, , $unit] = $this->createInventoryUnit();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ScanAsset::class)
            ->call('resolveScan', InventoryUnitQrPayload::encode($unit))
            ->assertSet('resolvedUnitId', $unit->id)
            ->assertSet('resolvedPropertyNumber', $unit->property_number)
            ->assertSet('resolvedCategoryId', $category->id)
            ->assertNoRedirect()
            ->assertSee('Disposal')
            ->assertSee('Incident report')
            ->assertSee('Transfer');
    }

    public function test_start_disposal_redirects_to_disposals_create_query(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, $category, , $unit] = $this->createInventoryUnit();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $expected = ScanAssetHandoff::disposalCreateUrl($unit, $category->id);

        Livewire::actingAs($custodian)
            ->test(ScanAsset::class)
            ->call('resolveScan', InventoryUnitQrPayload::encode($unit))
            ->call('startDisposal')
            ->assertRedirect($expected);
    }

    public function test_list_disposals_mounts_create_with_scanned_unit(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, $category, $item, $unit] = $this->createInventoryUnit();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($custodian);
        session(['active_item_category_id' => $category->id]);

        Livewire::withQueryParams([
            'category' => (string) $category->id,
            'create' => 1,
            'inventory_unit_id' => $unit->id,
        ])
            ->test(ListDisposals::class)
            ->assertActionMounted(TestAction::make('create')->schemaComponent(true, 'content'))
            ->assertSet('mountedActions.0.data.item_id', $item->id)
            ->assertSet('mountedActions.0.data.inventory_unit_id', $unit->id)
            ->assertSet('mountedActions.0.data.quantity', 1);
    }

    public function test_list_incident_reports_mounts_create_with_scanned_unit(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, , $item, $unit] = $this->createInventoryUnit();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($custodian);

        Livewire::withQueryParams([
            'create' => 1,
            'inventory_unit_id' => $unit->id,
        ])
            ->test(ListIncidentReports::class)
            ->assertActionMounted(TestAction::make('create')->schemaComponent(true, 'content'))
            ->assertSet('mountedActions.0.data.item_id', $item->id)
            ->assertSet('mountedActions.0.data.inventory_unit_id', $unit->id)
            ->assertSet('mountedActions.0.data.disposal_type', 'lost_stolen_damaged');
    }

    public function test_list_transfers_mounts_create_with_property_number(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, $category, $item, $unit] = $this->createInventoryUnit();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($custodian);
        session(['active_item_category_id' => $category->id]);

        Livewire::withQueryParams([
            'category' => (string) $category->id,
            'create' => 1,
            'item_id' => $item->id,
            'from_office' => $office->id,
            'property_number' => $unit->property_number,
        ])
            ->test(ListTransfers::class)
            ->assertActionMounted(TestAction::make('create')->schemaComponent(true, 'content'))
            ->assertSet('mountedActions.0.data.item_id', $item->id)
            ->assertSet('mountedActions.0.data.property_number', $unit->property_number)
            ->assertSet('mountedActions.0.data.quantity', 1);
    }

    public function test_property_returns_table_shows_reference_action_status_first(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($custodian);

        $livewire = Livewire::test(ListPropertyActionRequests::class);
        $columns = collect($livewire->instance()->getTable()->getColumns())
            ->map(fn ($column): string => $column->getName())
            ->values()
            ->all();

        $this->assertSame('reference_code', $columns[0] ?? null);
        $this->assertSame('action_type', $columns[1] ?? null);
        $this->assertSame('status', $columns[2] ?? null);
        $this->assertNotContains('reason_code', $columns);
        $this->assertNotContains('accountableUser.name', $columns);
        $this->assertNotContains('lines.issuance.item.category.name', $columns);
    }

    /**
     * @return array{0: Office, 1: ItemCategory, 2: Item, 3: InventoryUnit}
     */
    protected function createInventoryUnit(): array
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Tagged Asset',
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $acquisition = Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 5000,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        $unit = InventoryUnit::query()->create([
            'property_number' => 'TAG-'.uniqid(),
            'acquisition_id' => $acquisition->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'status' => InventoryUnit::STATUS_IN_STOCK,
            'article' => $item->name,
        ]);

        return [$office, $category, $item, $unit];
    }
}
