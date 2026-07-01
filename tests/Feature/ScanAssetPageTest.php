<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ScanAsset;
use App\Models\Acquisition;
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

    public function test_resolve_scan_redirects_to_public_asset_page_for_legacy_payload(): void
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
            ->assertRedirect(route('inventory.assets.show', ['propertyNumber' => $unit->property_number]));
    }

    public function test_resolve_scan_redirects_to_public_asset_page_for_url_payload(): void
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
            ->assertRedirect(route('inventory.assets.show', ['propertyNumber' => $unit->property_number]));
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
