<?php

namespace Tests\Feature;

use App\Filament\Resources\PropertyActionRequests\Pages\ListPropertyActionRequests;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Support\InventoryCategoryOptions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PropertyActionRequestCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_property_category_options_exclude_consumables(): void
    {
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $ppe = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'Property, plant and equipment (PPE)'],
        );

        $options = InventoryCategoryOptions::propertyCategoryOptions();

        $this->assertArrayNotHasKey($consumables->id, $options);
        $this->assertArrayHasKey($semi->id, $options);
        $this->assertArrayHasKey($ppe->id, $options);
    }

    public function test_create_form_shows_category_before_property_picker(): void
    {
        [$uc, $semiItem, $consumableItem] = $this->seedOfficeProperties();

        Livewire::actingAs($uc)
            ->test(ListPropertyActionRequests::class)
            ->mountAction('create')
            ->assertFormFieldExists('item_category_id');

        $options = InventoryCategoryOptions::propertyCategoryOptions();

        $this->assertArrayHasKey($semiItem->item_category_id, $options);
        $this->assertArrayNotHasKey($consumableItem->item_category_id, $options);
    }

    /**
     * @return array{0: User, 1: Item, 2: Item}
     */
    protected function seedOfficeProperties(): array
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $semiCategory = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $consumableCategory = ItemCategory::factory()->create(['name' => 'Consumables']);

        $semiItem = Item::factory()->create(['item_category_id' => $semiCategory->id]);
        $consumableItem = Item::factory()->create(['item_category_id' => $consumableCategory->id]);

        foreach ([$semiItem, $consumableItem] as $item) {
            $requisition = Requisition::query()->create([
                'reference_code' => 'REQ-'.$item->id,
                'office_id' => $office->id,
                'department_id' => $department->id,
                'requested_by' => $uc->id,
                'status' => Requisition::STATUS_ACCEPTED,
            ]);

            Issuance::query()->create([
                'requisition_id' => $requisition->id,
                'reference_code' => 'ISS-'.$item->id,
                'office_id' => $office->id,
                'department_id' => $department->id,
                'item_id' => $item->id,
                'quantity' => 1,
                'issuance_date' => now(),
                'issued_by' => $uc->id,
                'issued_to' => $uc->id,
                'property_number' => $item->id === $semiItem->id ? 'SPLV-TEST-001' : null,
            ]);
        }

        return [$uc, $semiItem, $consumableItem];
    }
}
