<?php

namespace Tests\Feature;

use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\InventoryQrLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuanceQrLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ppe_issuance_with_linked_units_downloads_qr_labels_pdf(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $issuance = $this->createIssuanceWithUnits('PPE', quantity: 2, unitCost: 55000);

        $this->actingAs($custodian)
            ->get(route('owwa.qr-labels.issuance', $issuance))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $labels = app(InventoryQrLabelService::class)->labelsForIssuance($issuance->fresh());
        $this->assertCount(2, $labels);
        $this->assertSame('Property No.', $labels->first()['property_number_label']);
        $this->assertSame('End User One', $labels->first()['end_user']);
    }

    public function test_semi_expendable_issuance_labels_use_sp_tag_no(): void
    {
        $issuance = $this->createIssuanceWithUnits('Semi-Expendable', quantity: 1, unitCost: 4500);

        $labels = app(InventoryQrLabelService::class)->labelsForIssuance($issuance);

        $this->assertCount(1, $labels);
        $this->assertSame('SP Tag No.', $labels->first()['property_number_label']);
        $this->assertTrue(app(InventoryQrLabelService::class)->supportsIssuanceQrLabels($issuance));
    }

    public function test_consumable_issuance_qr_labels_route_returns_not_found(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $issuance = $this->createIssuanceWithUnits('Consumables', quantity: 1, unitCost: 25, linkUnits: false);

        $this->assertFalse(app(InventoryQrLabelService::class)->supportsIssuanceQrLabels($issuance));

        $this->actingAs($custodian)
            ->get(route('owwa.qr-labels.issuance', $issuance))
            ->assertNotFound();
    }

    public function test_non_custodian_cannot_download_issuance_qr_labels(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $issuance = $this->createIssuanceWithUnits('PPE', quantity: 1, unitCost: 55000);

        $this->actingAs($employee)
            ->get(route('owwa.qr-labels.issuance', $issuance))
            ->assertForbidden();
    }

    protected function createIssuanceWithUnits(
        string $categoryName,
        int $quantity,
        float $unitCost,
        bool $linkUnits = true,
    ): Issuance {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => $categoryName]);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $endUser = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'name' => 'End User One',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        if ($linkUnits) {
            for ($i = 1; $i <= $quantity; $i++) {
                InventoryUnit::query()->create([
                    'property_number' => sprintf(
                        '%s-%s-%03d',
                        $categoryName === 'PPE' ? 'PPE' : 'SPLV',
                        now()->format('Y'),
                        $i,
                    ),
                    'item_id' => $item->id,
                    'office_id' => $office->id,
                    'unit_cost' => $unitCost,
                    'status' => InventoryUnit::STATUS_IN_STOCK,
                    'article' => $item->name,
                ]);
            }
        }

        $issuance = Issuance::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'issued_from_office_id' => $office->id,
            'requisition_id' => $requisition->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'amount' => round($unitCost * $quantity, 2),
            'issuance_date' => now()->toDateString(),
            'issued_by' => $custodian->id,
            'issued_to' => $endUser->id,
        ]);

        return $issuance->fresh(['item.category', 'inventoryUnits', 'issuedTo']) ?? $issuance;
    }
}
