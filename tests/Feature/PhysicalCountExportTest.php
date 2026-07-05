<?php

namespace Tests\Feature;

use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhysicalCountExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_physical_count_export_route_returns_download(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $session = PhysicalCountSession::query()->create([
            'reference_code' => 'PC-EXPORT-0001',
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'inventory_type_label' => 'Office Supplies Inventory',
        ]);

        session(['active_item_category_id' => $category->id]);

        $response = $this->actingAs($custodian)->get(route('owwa.export.physical-count', $session));

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    }
}
