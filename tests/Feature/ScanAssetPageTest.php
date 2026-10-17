<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ScanAsset;
use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Support\InventoryUnitQrPayload;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScanAssetPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_supply_custodian_can_load_scan_asset_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($custodian)
            ->get(ScanAsset::getUrl())
            ->assertOk()
            ->assertSee('owwa-scan-asset-page', false)
            ->assertSee('owwa-pa-page-title', false)
            ->assertSee('Scan asset')
            ->assertSee('Point your camera at the property QR tag')
            ->assertSee('asset-lookup-qr-reader', false)
            ->assertSee('Look up');
    }

    public function test_scan_asset_appears_in_sidebar_for_supply_custodian(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($custodian)
            ->get(Dashboard::getUrl())
            ->assertSee('Scan asset');
    }

    public function test_employee_cannot_access_scan_asset_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $this->actingAs($employee)
            ->get(ScanAsset::getUrl())
            ->assertForbidden();
    }

    public function test_resolve_scan_keeps_result_on_page_for_legacy_payload(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, , , $unit] = $this->createInventoryUnit('OWWA Regional Office IV-A');
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $legacyPayload = InventoryUnitQrPayload::encodeLegacy($unit);

        Livewire::actingAs($custodian)
            ->test(ScanAsset::class)
            ->call('resolveScan', $legacyPayload)
            ->assertSet('resolvedUnitId', $unit->id)
            ->assertNoRedirect();
    }

    public function test_resolve_scan_keeps_result_on_page_for_url_payload(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, , , $unit] = $this->createInventoryUnit();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $url = InventoryUnitQrPayload::publicUrl($unit);

        Livewire::actingAs($custodian)
            ->test(ScanAsset::class)
            ->call('resolveScan', $url)
            ->assertSet('resolvedUnitId', $unit->id)
            ->assertNoRedirect();
    }

    public function test_resolve_scan_keeps_result_on_page_for_legacy_pn_only_payload(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, , , $unit] = $this->createInventoryUnit();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $pnOnly = 'OWWA|1|pn='.$unit->property_number;

        Livewire::actingAs($custodian)
            ->test(ScanAsset::class)
            ->call('resolveScan', $pnOnly)
            ->assertSet('resolvedUnitId', $unit->id)
            ->assertNoRedirect();
    }

    public function test_resolve_scan_shows_notification_for_unknown_property_number(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ScanAsset::class)
            ->call('resolveScan', 'UNKNOWN-PROPERTY-999')
            ->assertNotified()
            ->assertNoRedirect();
    }

    public function test_typed_number_matching_several_units_requires_picking_one(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, , $item, $unit] = $this->createInventoryUnit();
        $duplicate = InventoryUnit::query()->create([
            'property_number' => $unit->property_number,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'status' => InventoryUnit::STATUS_IN_STOCK,
            'article' => 'Wall Clock',
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $page = Livewire::actingAs($custodian)
            ->test(ScanAsset::class)
            ->call('resolveScan', $unit->property_number)
            ->assertSet('resolvedUnitId', null);

        $this->assertEqualsCanonicalizing([$unit->id, $duplicate->id], array_keys($page->get('duplicateUnitOptions')));

        $page->call('selectDuplicateUnit', $duplicate->id)
            ->assertSet('resolvedUnitId', $duplicate->id)
            ->assertSet('duplicateUnitOptions', []);
    }

    public function test_claimed_unit_still_shows_card_and_allows_reprint(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, , $item, $unit] = $this->createInventoryUnit();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        Disposal::query()->create([
            'disposal_type' => 'unserviceable',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'disposal_date' => now()->toDateString(),
            'inventory_unit_id' => $unit->id,
            'recorded_by' => $custodian->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ScanAsset::class)
            ->call('resolveScan', InventoryUnitQrPayload::encode($unit))
            ->assertSet('resolvedUnitId', $unit->id)
            ->assertSet('resolvedIsClaimed', true)
            ->assertDontSeeHtml('wire:click="startDisposal"')
            ->assertActionVisible('reprintQrLabel')
            ->callAction('reprintQrLabel')
            ->assertRedirect(route('owwa.qr-labels.unit', ['inventoryUnit' => $unit->id]));
    }

    public function test_disposed_unit_is_rejected_for_reprint(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, , , $unit] = $this->createInventoryUnit();
        $unit->update(['status' => InventoryUnit::STATUS_DISPOSED]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ScanAsset::class)
            ->call('resolveScan', InventoryUnitQrPayload::encode($unit))
            ->assertSet('resolvedUnitId', null)
            ->assertNotified('Unit already disposed');
    }

    /**
     * @return array{0: Office, 1: ItemCategory, 2: Item, 3: InventoryUnit}
     */
    protected function createInventoryUnit(string $officeName = 'OWWA Regional Office IV-A'): array
    {
        $office = Office::factory()->create(['name' => $officeName]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Wall Clock',
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $acquisition = Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 500,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        $unit = InventoryUnit::query()->create([
            'property_number' => 'SPLV-2026-OE-106-OWWA-IVA-004',
            'acquisition_id' => $acquisition->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'status' => InventoryUnit::STATUS_IN_STOCK,
            'article' => 'Wall Clock',
        ]);

        return [$office, $category, $item, $unit];
    }
}
