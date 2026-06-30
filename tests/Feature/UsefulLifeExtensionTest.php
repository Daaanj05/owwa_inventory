<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\UsefulLifeExtensionService;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsefulLifeExtensionTest extends TestCase
{
    use RefreshDatabase;

    public function test_extension_updates_issuance_eul_and_creates_audit_row(): void
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'estimated_useful_life' => '3 yrs',
        ]);
        $approver = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0500',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $approver->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => '2026-01-0501',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'issuance_date' => '2024-01-01',
            'issued_by' => $approver->id,
            'estimated_useful_life' => '3 yrs',
        ]);

        $previousExpires = $issuance->eul_expires_at;

        app(UsefulLifeExtensionService::class)->extend(
            $issuance,
            '5 yrs',
            'Item still serviceable after original EUL.',
            $approver,
        );

        $issuance->refresh();

        $this->assertSame('5 yrs', $issuance->estimated_useful_life);
        $this->assertNotSame($previousExpires?->toDateString(), $issuance->eul_expires_at?->toDateString());
        $this->assertSame(
            SemiExpendableUsefulLife::computeExpiresAt($issuance->issuance_date, '5 yrs')?->toDateString(),
            $issuance->eul_expires_at?->toDateString(),
        );

        $this->assertDatabaseHas('useful_life_extensions', [
            'issuance_id' => $issuance->id,
            'previous_eul' => '3 yrs',
            'new_eul' => '5 yrs',
            'approved_by' => $approver->id,
        ]);
    }
}
