<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Support\ConsumableInventoryType;
use App\Support\ItemPropertyClass;
use App\Support\PhysicalCountPropertyClassResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhysicalCountPropertyClassResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_class_returns_single_class_from_lines(): void
    {
        $session = $this->createRpcspSession();
        $item = Item::factory()->ict()->create(['item_category_id' => $session->item_category_id]);

        PhysicalCountLine::query()->create([
            'physical_count_session_id' => $session->id,
            'item_id' => $item->id,
            'balance_per_card' => 1,
            'on_hand_count' => 1,
        ]);

        $this->assertSame(ItemPropertyClass::Ict, PhysicalCountPropertyClassResolver::primaryClass($session->fresh()));
        $this->assertSame(
            'INFORMATION & COMMUNICATION TECHNOLOGY',
            PhysicalCountPropertyClassResolver::inventoryTypeLabel($session->fresh()),
        );
    }

    public function test_mixed_classes_return_null_primary_and_multiple_classes(): void
    {
        $session = $this->createRpcspSession();
        $ictItem = Item::factory()->ict()->create(['item_category_id' => $session->item_category_id]);
        $sportsItem = Item::factory()->sportsEquipment()->create(['item_category_id' => $session->item_category_id]);

        foreach ([$ictItem, $sportsItem] as $item) {
            PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'balance_per_card' => 1,
                'on_hand_count' => 1,
            ]);
        }

        $session = $session->fresh();

        $this->assertNull(PhysicalCountPropertyClassResolver::primaryClass($session));
        $this->assertCount(2, PhysicalCountPropertyClassResolver::classesForSession($session));
        $this->assertSame('', PhysicalCountPropertyClassResolver::inventoryTypeLabel($session));
    }

    public function test_sync_session_sets_fields_for_single_class(): void
    {
        $session = $this->createRpcspSession();
        $item = Item::factory()->ict()->create(['item_category_id' => $session->item_category_id]);

        PhysicalCountLine::query()->create([
            'physical_count_session_id' => $session->id,
            'item_id' => $item->id,
            'balance_per_card' => 1,
            'on_hand_count' => 0,
        ]);

        PhysicalCountPropertyClassResolver::syncSession($session->fresh());

        $session->refresh();
        $this->assertSame(ItemPropertyClass::Ict, $session->property_class);
        $this->assertSame('INFORMATION & COMMUNICATION TECHNOLOGY', $session->inventory_type_label);
    }

    public function test_sync_session_clears_fields_for_mixed_classes(): void
    {
        $session = $this->createRpcspSession([
            'property_class' => ItemPropertyClass::Ict,
            'inventory_type_label' => 'INFORMATION & COMMUNICATION TECHNOLOGY',
        ]);
        $ictItem = Item::factory()->ict()->create(['item_category_id' => $session->item_category_id]);
        $sportsItem = Item::factory()->sportsEquipment()->create(['item_category_id' => $session->item_category_id]);

        foreach ([$ictItem, $sportsItem] as $item) {
            PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'balance_per_card' => 1,
                'on_hand_count' => 1,
            ]);
        }

        PhysicalCountPropertyClassResolver::syncSession($session->fresh());

        $session->refresh();
        $this->assertNull($session->property_class);
        $this->assertNull($session->inventory_type_label);
    }

    public function test_rpci_sync_session_sets_inventory_type_from_counted_items(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
        ]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'inventory_type' => ConsumableInventoryType::AccountableForms,
        ]);

        PhysicalCountLine::query()->create([
            'physical_count_session_id' => $session->id,
            'item_id' => $item->id,
            'balance_per_card' => 5,
            'on_hand_count' => 4,
        ]);

        PhysicalCountPropertyClassResolver::syncSession($session->fresh());

        $session->refresh();
        $this->assertSame(ConsumableInventoryType::AccountableForms, $session->inventory_type);
        $this->assertSame(
            ConsumableInventoryType::label(ConsumableInventoryType::AccountableForms),
            $session->inventory_type_label,
        );
        $this->assertTrue($session->usesDerivedInventoryTypeLabel());
        $this->assertSame(
            ConsumableInventoryType::label(ConsumableInventoryType::AccountableForms),
            PhysicalCountPropertyClassResolver::displayInventoryTypeText($session),
        );
    }

    public function test_rpci_sync_session_clears_inventory_type_for_mixed_item_types(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'inventory_type' => ConsumableInventoryType::OfficeSupplies,
            'inventory_type_label' => ConsumableInventoryType::label(ConsumableInventoryType::OfficeSupplies),
        ]);

        $officeSupplies = Item::factory()->create([
            'item_category_id' => $category->id,
            'inventory_type' => ConsumableInventoryType::OfficeSupplies,
        ]);
        $food = Item::factory()->create([
            'item_category_id' => $category->id,
            'inventory_type' => ConsumableInventoryType::FoodSupplies,
        ]);

        foreach ([$officeSupplies, $food] as $item) {
            PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'balance_per_card' => 1,
                'on_hand_count' => 1,
            ]);
        }

        PhysicalCountPropertyClassResolver::syncSession($session->fresh());

        $session->refresh();
        $this->assertNull($session->inventory_type);
        $this->assertNull($session->inventory_type_label);
        $this->assertSame(
            'Multiple inventory types',
            PhysicalCountPropertyClassResolver::displayInventoryTypeText($session),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createRpcspSession(array $overrides = []): PhysicalCountSession
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        return PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            ...$overrides,
        ]);
    }
}
