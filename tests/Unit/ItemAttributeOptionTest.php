<?php

namespace Tests\Unit;

use App\Models\ItemAttributeOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemAttributeOptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_for_kind_return_active_value_label_pairs(): void
    {
        ItemAttributeOption::query()->create([
            'kind' => ItemAttributeOption::KIND_UNIT,
            'value' => 'piece',
            'label' => 'piece',
            'is_active' => true,
        ]);
        ItemAttributeOption::query()->create([
            'kind' => ItemAttributeOption::KIND_UNIT,
            'value' => 'box',
            'label' => 'box',
            'is_active' => false,
        ]);
        ItemAttributeOption::query()->create([
            'kind' => ItemAttributeOption::KIND_INVENTORY_TYPE,
            'value' => 'office_supplies',
            'label' => 'Office Supplies',
            'is_active' => true,
        ]);

        $this->assertSame(['piece' => 'piece'], ItemAttributeOption::optionsForKind(ItemAttributeOption::KIND_UNIT));
        $this->assertSame(
            ['office_supplies' => 'Office Supplies'],
            ItemAttributeOption::optionsForKind(ItemAttributeOption::KIND_INVENTORY_TYPE),
        );
    }
}
