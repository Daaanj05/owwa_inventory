<?php

namespace Tests\Feature;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RisRsmiExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_rsmi_route_exports_when_consumable_issuances_exist_today(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0400',
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 3,
            'issuance_date' => now()->toDateString(),
            'reference_code' => '2026-01-0501',
            'issued_by' => $custodian->id,
        ]);

        session(['active_item_category_id' => $category->id]);

        $response = $this->actingAs($custodian)->get(route('owwa.export.issuances.today-rsmi'));

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringContainsString(
            'RSMI-2026-01-0501',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_today_rsmi_route_returns_not_found_when_no_issuances_today(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        Issuance::query()->create([
            'office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'issuance_date' => now()->subDay()->toDateString(),
            'reference_code' => '2026-01-0502',
            'issued_by' => $custodian->id,
            'requisition_id' => Requisition::query()->create([
                'reference_code' => '2026-01-0401',
                'office_id' => $office->id,
                'requested_by' => $custodian->id,
                'status' => Requisition::STATUS_ACCEPTED,
            ])->id,
        ]);

        session(['active_item_category_id' => $category->id]);

        $this->actingAs($custodian)
            ->get(route('owwa.export.issuances.today-rsmi'))
            ->assertNotFound();
    }

    public function test_requisition_export_route_returns_ris_spreadsheet(): void
    {
        $office = Office::factory()->create();
        /** @var User $uc */
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0410',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $response = $this->actingAs($custodian)
            ->get(route('owwa.export.requisition', $requisition));

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringContainsString(
            'RIS-2026-01-0410.xlsx',
            (string) $response->headers->get('content-disposition'),
        );
    }
}
