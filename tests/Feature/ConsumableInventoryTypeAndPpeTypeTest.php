<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\User;
use App\Services\OwwaItemReportService;
use App\Support\ConsumableInventoryType;
use App\Support\ItemPropertyClass;
use App\Support\PhysicalCountPropertyClassResolver;
use App\Support\PpePropertyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumableInventoryTypeAndPpeTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_type_columns_are_isolated_by_category(): void
    {
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $ppe = ItemCategory::factory()->create(['name' => 'PPE']);

        $consumable = Item::factory()->create([
            'item_category_id' => $consumables->id,
            'inventory_type' => ConsumableInventoryType::OfficeSupplies,
            'property_class' => null,
            'ppe_type' => null,
        ]);

        $semiItem = Item::factory()->create([
            'item_category_id' => $semi->id,
            'property_class' => ItemPropertyClass::OfficeEquipment,
            'inventory_type' => null,
            'ppe_type' => null,
        ]);

        $ppeItem = Item::factory()->create([
            'item_category_id' => $ppe->id,
            'ppe_type' => PpePropertyType::FurnitureFixturesBooks,
            'property_class' => null,
            'inventory_type' => null,
        ]);

        $this->assertSame(ConsumableInventoryType::OfficeSupplies, $consumable->fresh()->inventory_type);
        $this->assertNull($consumable->fresh()->property_class);
        $this->assertNull($consumable->fresh()->ppe_type);

        $this->assertSame(ItemPropertyClass::OfficeEquipment, $semiItem->fresh()->property_class);
        $this->assertNull($semiItem->fresh()->ppe_type);
        $this->assertNull($semiItem->fresh()->inventory_type);

        $this->assertSame(PpePropertyType::FurnitureFixturesBooks, $ppeItem->fresh()->ppe_type);
        $this->assertNull($ppeItem->fresh()->property_class);
        $this->assertNull($ppeItem->fresh()->inventory_type);
    }

    public function test_rpci_header_uses_session_inventory_type_label(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'inventory_type' => ConsumableInventoryType::AccountableForms,
        ]);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'accountable_officer_name' => 'Officer',
            'recorded_by' => User::factory()->create()->id,
        ]);

        $session->lines()->create([
            'item_id' => $item->id,
            'article' => $item->name,
            'balance_per_card' => 1,
            'on_hand_count' => 1,
        ]);

        PhysicalCountPropertyClassResolver::syncSession($session->fresh(['lines.item']));

        $method = new \ReflectionMethod(OwwaItemReportService::class, 'physicalCountHeaderData');
        $header = $method->invoke(app(OwwaItemReportService::class), $session->fresh());

        $this->assertSame(
            ConsumableInventoryType::label(ConsumableInventoryType::AccountableForms),
            $header['inventory_type'],
        );
    }

    public function test_rpcppe_header_uses_ppe_type_not_property_class(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'ppe_type' => PpePropertyType::TechnicalScientificEquipment,
            'property_class' => ItemPropertyClass::OfficeEquipment,
        ]);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'ppe_type' => PpePropertyType::TechnicalScientificEquipment,
            'inventory_type_label' => PpePropertyType::propertyTypeLabel(PpePropertyType::TechnicalScientificEquipment),
            'accountable_officer_name' => 'Officer',
            'recorded_by' => User::factory()->create()->id,
            'book_list_loaded' => true,
        ]);

        $session->lines()->create([
            'item_id' => $item->id,
            'article' => $item->name,
            'property_number' => 'PPE-1',
            'balance_per_card' => 1,
            'on_hand_count' => 1,
        ]);

        $method = new \ReflectionMethod(OwwaItemReportService::class, 'physicalCountHeaderData');
        $header = $method->invoke(
            app(OwwaItemReportService::class),
            $session->fresh(['lines.item']),
            PpePropertyType::TechnicalScientificEquipment,
        );

        $this->assertSame(
            PpePropertyType::propertyTypeLabel(PpePropertyType::TechnicalScientificEquipment),
            $header['inventory_type'],
        );
    }
}
