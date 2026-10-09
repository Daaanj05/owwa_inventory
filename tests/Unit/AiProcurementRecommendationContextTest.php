<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\AiProcurementRecommendationService;
use App\Services\SemiExpendableEulAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiProcurementRecommendationContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_includes_consumable_reorders_and_replacement_due_rows_only(): void
    {
        $office = Office::factory()->create();
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        $consumable = Item::factory()->create([
            'item_category_id' => $consumables->id,
            'name' => 'Folder Short',
            'reorder_level' => 30,
        ]);
        $basketball = Item::factory()->create([
            'item_category_id' => $semi->id,
            'name' => 'Basketball',
            'reorder_level' => 0,
        ]);
        $chair = Item::factory()->create([
            'item_category_id' => $semi->id,
            'name' => 'Office Chair',
        ]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-AI-CONS-'.uniqid(),
            'item_id' => $consumable->id,
            'office_id' => $office->id,
            'quantity' => 4,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 5; $i >= 0; $i--) {
            DB::table('issuances')->insert([
                'reference_code' => 'ISS-AI-BALL-'.$i.'-'.uniqid(),
                'item_id' => $basketball->id,
                'office_id' => $office->id,
                'quantity' => 2,
                'issuance_date' => now()->subMonths($i)->startOfMonth()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-AI-BALL-'.uniqid(),
            'item_id' => $basketball->id,
            'office_id' => $office->id,
            'quantity' => 12,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('issuances')->insert([
            'reference_code' => 'ISS-AI-CHAIR-'.uniqid(),
            'item_id' => $chair->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'issuance_date' => now()->subYears(4)->toDateString(),
            'estimated_useful_life' => '5 years',
            'eul_expires_at' => now()->addDays(20)->toDateString(),
            'property_number' => 'SEMI-CHAIR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $context = app(AiProcurementRecommendationService::class)->collectSourceRows(
            from: now()->subMonths(5)->startOfMonth(),
            to: now()->endOfMonth(),
            categoryId: null,
            officeIds: [$office->id],
            categoryIds: [$consumables->id, $semi->id],
        );

        $reorderIds = $context['reorders']->pluck('item_id')->all();
        $replacementNames = $context['replacements']->pluck('item_name')->all();

        $this->assertContains($consumable->id, $reorderIds);
        $this->assertNotContains($basketball->id, $reorderIds);
        $this->assertNotContains('Basketball', $replacementNames);
        $this->assertContains('Office Chair', $replacementNames);
        $this->assertSame(
            SemiExpendableEulAnalyticsService::ACTION_AWAITING_REVIEW,
            $context['replacements']->firstWhere('item_name', 'Office Chair')->action,
        );
    }
}
