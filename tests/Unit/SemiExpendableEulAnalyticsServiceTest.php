<?php

namespace Tests\Unit;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\SemiExpendableEulAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SemiExpendableEulAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_replacement_action_follows_report_and_unissued_stock(): void
    {
        $office = Office::factory()->create();
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $inStockItem = Item::factory()->create([
            'item_category_id' => $semi->id,
            'name' => 'Chair In Stock',
        ]);
        $purchaseItem = Item::factory()->create([
            'item_category_id' => $semi->id,
            'name' => 'Chair To Buy',
        ]);

        $this->insertAcquisition($inStockItem->id, $office->id, 2);
        $this->insertIssuance($inStockItem->id, $office->id, 'SEMI-STOCK', Issuance::USEFUL_LIFE_NEEDS_REPLACEMENT);
        $this->insertAcquisition($purchaseItem->id, $office->id, 1);
        $this->insertIssuance($purchaseItem->id, $office->id, 'SEMI-BUY', Issuance::USEFUL_LIFE_NEEDS_REPLACEMENT);

        $awaitingItem = Item::factory()->create([
            'item_category_id' => $semi->id,
            'name' => 'Chair Awaiting',
        ]);
        $this->insertIssuance($awaitingItem->id, $office->id, 'SEMI-WAIT', null);

        $rows = app(SemiExpendableEulAnalyticsService::class)
            ->getReviewRows([$office->id])
            ->keyBy('item_name');

        $this->assertSame(SemiExpendableEulAnalyticsService::ACTION_ISSUE_FROM_STOCK, $rows['Chair In Stock']->action);
        $this->assertGreaterThan(0, $rows['Chair In Stock']->unissued_stock);
        $this->assertSame(
            'Issue from stock ('.number_format($rows['Chair In Stock']->unissued_stock).' on hand)',
            $rows['Chair In Stock']->action_label,
        );
        $this->assertSame(SemiExpendableEulAnalyticsService::ACTION_PURCHASE, $rows['Chair To Buy']->action);
        $this->assertSame('Purchase', $rows['Chair To Buy']->action_label);
        $this->assertSame(0, $rows['Chair To Buy']->unissued_stock);
        $this->assertSame(SemiExpendableEulAnalyticsService::ACTION_AWAITING_REVIEW, $rows['Chair Awaiting']->action);
        $this->assertSame('Awaiting review', $rows['Chair Awaiting']->action_label);
    }

    protected function insertAcquisition(int $itemId, int $officeId, int $quantity): void
    {
        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-EUL-'.$itemId.'-'.uniqid(),
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => $quantity,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function insertIssuance(int $itemId, int $officeId, string $propertyNumber, ?string $condition): void
    {
        DB::table('issuances')->insert([
            'reference_code' => 'ISS-EUL-'.$propertyNumber.'-'.uniqid(),
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => 1,
            'issuance_date' => now()->subYears(4)->toDateString(),
            'estimated_useful_life' => '5 years',
            'eul_expires_at' => now()->addDays(20)->toDateString(),
            'property_number' => $propertyNumber,
            'useful_life_condition' => $condition,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
