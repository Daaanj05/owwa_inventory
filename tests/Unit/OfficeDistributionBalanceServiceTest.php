<?php

namespace Tests\Unit;

use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\OfficeDistributionBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficeDistributionBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_quantity_is_issued_minus_distributed(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => 'REQ-BAL-1',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'ISS-BAL-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 10,
            'issuance_date' => now(),
            'issued_by' => $uc->id,
            'issued_to' => $uc->id,
        ]);

        Distribution::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 3,
            'distribution_date' => now(),
            'distributed_by' => $uc->id,
            'distributed_to' => User::factory()->create([
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $office->id,
            ])->id,
        ]);

        $service = app(OfficeDistributionBalanceService::class);

        $this->assertSame(10, $service->issuedQuantity($item->id, $office->id));
        $this->assertSame(3, $service->distributedQuantity($item->id, $office->id));
        $this->assertSame(7, $service->availableQuantity($item->id, $office->id));
    }
}
