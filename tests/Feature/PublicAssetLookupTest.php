<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Support\InventoryUnitQrPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAssetLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_asset_page_shows_sticker_details(): void
    {
        [$office, $category, $item, $unit] = $this->createInventoryUnitFixtures();

        $response = $this->get(route('inventory.assets.show', ['propertyNumber' => $unit->property_number]));

        $response->assertOk()
            ->assertSee('Semi-Expendable Property No.')
            ->assertSee('Semi-Expendable Property')
            ->assertSee('Description')
            ->assertSee('Unit / Section')
            ->assertSee('Stock No.')
            ->assertSee('Acquisition Cost')
            ->assertSee('Date Acquired')
            ->assertSee($unit->property_number)
            ->assertSee($item->name)
            ->assertSee($office->name)
            ->assertSee('₱500.00')
            ->assertDontSee('In stock')
            ->assertDontSee('Open in admin')
            ->assertDontSee('custodian_printed_name')
            ->assertDontSee('received_from_name');
    }

    public function test_public_asset_page_hides_admin_link_for_custodian(): void
    {
        [$office, $category, $item, $unit] = $this->createInventoryUnitFixtures();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        session()->put('active_item_category_id', $category->id);

        $this->actingAs($custodian)
            ->get(route('inventory.assets.show', ['propertyNumber' => $unit->property_number]))
            ->assertOk()
            ->assertDontSee('Open in admin')
            ->assertDontSee('In stock');
    }

    public function test_public_asset_page_returns_not_found_for_unknown_property_number(): void
    {
        $this->get(route('inventory.assets.show', ['propertyNumber' => 'UNKNOWN-9999']))
            ->assertNotFound();
    }

    public function test_public_asset_page_hidden_when_feature_disabled(): void
    {
        config(['inventory.qr_public_lookup' => false]);

        [, , , $unit] = $this->createInventoryUnitFixtures();

        $this->get(route('inventory.assets.show', ['propertyNumber' => $unit->property_number]))
            ->assertNotFound();
    }

    public function test_qr_encode_uses_public_url_when_lookup_enabled(): void
    {
        config(['inventory.qr_public_lookup' => true]);

        $unit = new InventoryUnit([
            'property_number' => 'PPE-2026-0099',
            'item_id' => 12,
            'office_id' => 3,
        ]);

        $encoded = InventoryUnitQrPayload::encode($unit);

        $this->assertStringContainsString('/assets/', $encoded);
        $this->assertSame('PPE-2026-0099', InventoryUnitQrPayload::parseFromUrl($encoded)?->propertyNumber);
    }

    public function test_legacy_qr_payload_still_parses(): void
    {
        $unit = new InventoryUnit([
            'property_number' => 'PPE-2026-0099',
            'item_id' => 12,
            'office_id' => 3,
            'stock_number' => 'PPE-001',
        ]);

        $legacy = InventoryUnitQrPayload::encodeLegacy($unit);
        $parsed = InventoryUnitQrPayload::parse($legacy);

        $this->assertNotNull($parsed);
        $this->assertSame('PPE-2026-0099', $parsed->propertyNumber);
        $this->assertSame(12, $parsed->itemId);
        $this->assertSame(3, $parsed->officeId);
    }

    /**
     * @return array{0: Office, 1: ItemCategory, 2: Item, 3: InventoryUnit}
     */
    protected function createInventoryUnitFixtures(): array
    {
        $office = Office::factory()->create(['name' => 'OWWA Regional Office IV-A']);
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
