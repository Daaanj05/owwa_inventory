<?php

namespace Tests\Feature;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IssuancePropertyNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_semi_issuance_without_acquisition_unit_fails(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-07-0600',
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $this->expectException(ValidationException::class);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'issued_to' => $custodian->id,
        ]);
    }
}
