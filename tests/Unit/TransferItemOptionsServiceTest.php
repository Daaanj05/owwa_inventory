<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\TransferItemOptionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransferItemOptionsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_includes_item_with_zero_stock_when_history_exists(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $this->createAcquisition($item->id, $office->id, 3);
        $this->createIssuance($item->id, $office->id, 3);

        $options = app(TransferItemOptionsService::class)->optionsForFromOffice($office->id, $category->id);

        $this->assertArrayHasKey($item->id, $options);
        $this->assertStringContainsString('0 available', $options[$item->id]);
    }

    public function test_excludes_catalog_only_item_without_office_activity(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $catalogOnly = Item::factory()->create(['item_category_id' => $category->id]);
        $stocked = Item::factory()->create(['item_category_id' => $category->id]);

        $this->createAcquisition($stocked->id, $office->id, 2);

        $options = app(TransferItemOptionsService::class)->optionsForFromOffice($office->id, $category->id);

        $this->assertArrayNotHasKey($catalogOnly->id, $options);
        $this->assertArrayHasKey($stocked->id, $options);
    }

    protected function createAcquisition(int $itemId, int $officeId, int $quantity): void
    {
        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-TEST-'.$itemId.'-'.$officeId.'-'.uniqid(),
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => $quantity,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createIssuance(int $itemId, int $officeId, int $quantity): void
    {
        DB::table('issuances')->insert([
            'reference_code' => 'ISS-TEST-'.$itemId.'-'.$officeId.'-'.uniqid(),
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => $quantity,
            'issuance_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
