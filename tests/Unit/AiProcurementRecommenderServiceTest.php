<?php

namespace Tests\Unit;

use App\Models\AiProcurementItem;
use App\Models\AiProcurementRun;
use App\Models\Item;
use App\Services\AiProcurementRecommenderService;
use App\Services\OllamaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AiProcurementRecommenderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_and_merge_drops_unknown_ids_and_fills_missing_highs(): void
    {
        $run = $this->makeRunWithHighs([
            ['name' => 'Alcohol', 'stock' => 4, 'suggested' => 16, 'usage' => 6.5],
            ['name' => 'Bond Paper', 'stock' => 8, 'suggested' => 32, 'usage' => 13.0],
        ]);

        $service = app(AiProcurementRecommenderService::class);
        $highs = $run->items->where('priority', 'High')->values();
        $ids = $highs->pluck('item_id')->map(fn ($id) => (int) $id)->all();

        $merged = $service->validateAndMerge($highs, collect(), [
            'next_action' => 'Prepare one PR for High items first.',
            'ranked_high_item_ids' => [$ids[1], 99999],
            'why_by_item_id' => [
                (string) $ids[1] => 'Stock is below reorder with only weeks of cover.',
            ],
        ]);

        $this->assertSame('ai', $merged['source']);
        $this->assertSame([$ids[1], $ids[0]], $merged['ranked_high_item_ids']);
        $this->assertArrayHasKey((string) $ids[1], $merged['why_by_item_id']);
        $this->assertArrayNotHasKey((string) $ids[0], $merged['why_by_item_id']);
        $this->assertStringContainsString('Focus the purchase request on', $merged['next_action']);
    }

    public function test_validate_and_merge_accepts_why_keyed_by_item_name(): void
    {
        $run = $this->makeRunWithHighs([
            ['name' => 'Alcohol', 'stock' => 4, 'suggested' => 16, 'usage' => 6.5],
        ]);

        $service = app(AiProcurementRecommenderService::class);
        $highs = $run->items->where('priority', 'High')->values();
        $id = (int) $highs->first()->item_id;

        $merged = $service->validateAndMerge($highs, collect(), [
            'next_action' => 'Prioritize Alcohol because cover is thin, then open one PR this cycle for the High list.',
            'ranked_high_item_ids' => [$id],
            'why_by_item_id' => [
                'Alcohol' => 'Stock 4 sits below reorder 10 with only 3 weeks of cover.',
            ],
        ]);

        $this->assertSame(
            'Stock 4 sits below reorder 10 with only 3 weeks of cover.',
            $merged['why_by_item_id'][(string) $id],
        );
        $this->assertGreaterThanOrEqual(22, str_word_count($merged['next_action']));
    }

    public function test_validate_and_merge_rejects_meta_instruction_next_action(): void
    {
        $run = $this->makeRunWithHighs([
            ['name' => 'Alcohol', 'stock' => 4, 'suggested' => 16, 'usage' => 6.5],
        ]);

        $service = app(AiProcurementRecommenderService::class);
        $highs = $run->items->where('priority', 'High')->values();
        $id = (int) $highs->first()->item_id;

        $merged = $service->validateAndMerge($highs, collect(), [
            'next_action' => 'Two sentences naming the top High items and the custodian next step.',
            'ranked_high_item_ids' => [$id],
            'why_by_item_id' => [
                (string) $id => 'One sentence using that item\'s facts only.',
            ],
        ]);

        $this->assertStringNotContainsString('Two sentences naming', $merged['next_action']);
        $this->assertStringContainsString('Focus the purchase request on Alcohol', $merged['next_action']);
        $this->assertSame([], $merged['why_by_item_id']);
    }

    public function test_validate_and_merge_rejects_why_with_invented_digits(): void
    {
        $run = $this->makeRunWithHighs([
            ['name' => 'Alcohol', 'stock' => 4, 'suggested' => 16, 'usage' => 6.5],
        ]);

        $service = app(AiProcurementRecommenderService::class);
        $highs = $run->items->where('priority', 'High')->values();
        $id = (int) $highs->first()->item_id;

        $merged = $service->validateAndMerge($highs, collect(), [
            'next_action' => 'Buy High items.',
            'ranked_high_item_ids' => [$id],
            'why_by_item_id' => [
                (string) $id => 'Stock is only 99 and you must buy 500 units.',
            ],
        ]);

        $this->assertSame([], $merged['why_by_item_id']);
    }

    public function test_validate_and_merge_rejects_why_with_invented_flag_claims(): void
    {
        $run = $this->makeRunWithHighs([
            [
                'name' => 'Alcohol',
                'stock' => 4,
                'suggested' => 16,
                'usage' => 6.5,
                'flags' => ['below_reorder', 'file_pr_soon'],
            ],
        ]);

        $service = app(AiProcurementRecommenderService::class);
        $highs = $run->items->where('priority', 'High')->values();
        $id = (int) $highs->first()->item_id;

        $merged = $service->validateAndMerge($highs, collect(), [
            'next_action' => 'Prepare one PR for Alcohol this cycle with the suggested buy quantity below.',
            'ranked_high_item_ids' => [$id],
            'why_by_item_id' => [
                (string) $id => 'One large issuance may be inflating forecast for this item.',
            ],
        ]);

        $this->assertSame([], $merged['why_by_item_id']);
        $this->assertSame(
            ['below_reorder', 'file_pr_soon'],
            $merged['flags_by_item_id'][(string) $id],
        );
    }

    public function test_validate_and_merge_keeps_why_when_flag_is_present(): void
    {
        $run = $this->makeRunWithHighs([
            [
                'name' => 'Alcohol',
                'stock' => 4,
                'suggested' => 16,
                'usage' => 6.5,
                'flags' => ['below_reorder', 'review_forecast', 'file_pr_soon'],
                'spike_share' => 0.65,
            ],
        ]);

        $service = app(AiProcurementRecommenderService::class);
        $highs = $run->items->where('priority', 'High')->values();
        $id = (int) $highs->first()->item_id;

        $merged = $service->validateAndMerge($highs, collect(), [
            'next_action' => 'Review the forecast for Alcohol then prepare one PR using suggested buy quantities.',
            'ranked_high_item_ids' => [$id],
            'why_by_item_id' => [
                (string) $id => 'Stock 4 is below reorder 10; one large issuance may be inflating forecast.',
            ],
        ]);

        $this->assertSame(
            'Stock 4 is below reorder 10; one large issuance may be inflating forecast.',
            $merged['why_by_item_id'][(string) $id],
        );
        $this->assertContains('review_forecast', $merged['flags_by_item_id'][(string) $id]);
    }

    public function test_validate_and_merge_replaces_raw_item_id_with_name(): void
    {
        $run = $this->makeRunWithHighs([
            ['name' => 'Alcohol 70% 500ml', 'stock' => 4, 'suggested' => 16, 'usage' => 6.5],
        ]);

        $service = app(AiProcurementRecommenderService::class);
        $highs = $run->items->where('priority', 'High')->values();
        $id = (int) $highs->first()->item_id;

        $merged = $service->validateAndMerge($highs, collect(), [
            'next_action' => "Review reorder levels for item_id {$id} and consider increasing stock.",
            'ranked_high_item_ids' => [$id],
            'why_by_item_id' => [
                (string) $id => "item_id {$id} is below reorder with thin cover.",
            ],
        ]);

        $this->assertStringStartsWith(
            'Review reorder levels for Alcohol 70% 500ml and consider increasing stock.',
            $merged['next_action'],
        );
        $this->assertStringContainsString('Focus the purchase request on Alcohol 70% 500ml', $merged['next_action']);
        $this->assertSame(
            'Alcohol 70% 500ml is below reorder with thin cover.',
            $merged['why_by_item_id'][(string) $id],
        );
        $this->assertStringNotContainsString('item_id', $merged['next_action']);
    }

    public function test_recommend_falls_back_when_ollama_unavailable(): void
    {
        $run = $this->makeRunWithHighs([
            ['name' => 'Alcohol', 'stock' => 4, 'suggested' => 16, 'usage' => 6.5],
        ]);

        $ollama = Mockery::mock(OllamaClient::class);
        $ollama->shouldReceive('isAvailable')->andReturn(false);
        $this->app->instance(OllamaClient::class, $ollama);

        $result = app(AiProcurementRecommenderService::class)->recommend($run->fresh(['items']));

        $this->assertSame('fallback', $result['source']);
        $this->assertNotSame('', $result['next_action']);
        $this->assertSame([], $result['why_by_item_id']);
        $this->assertCount(1, $result['ranked_high_item_ids']);
        $this->assertSame('', $result['semi_next_action']);
    }

    public function test_validate_and_merge_sets_semi_next_action(): void
    {
        $run = $this->makeRunWithHighs([
            ['name' => 'Alcohol', 'stock' => 4, 'suggested' => 16, 'usage' => 6.5],
        ]);
        AiProcurementItem::query()->create([
            'run_id' => $run->id,
            'section' => 'replacement',
            'priority' => 'Medium',
            'item_name' => 'Desk Organizer',
            'property_number' => 'SPH-SEM-003',
            'eul_status' => 'Nearing expiry',
            'replacement_action' => 'issue_from_stock',
            'reason' => 'Issue from stock',
            'include_in_request' => false,
        ]);
        $run = $run->fresh(['items']);

        $service = app(AiProcurementRecommenderService::class);
        $highs = $run->items->where('priority', 'High')->where('section', '!=', 'replacement')->values();
        $semis = $run->items->where('section', 'replacement')->values();
        $id = (int) $highs->first()->item_id;

        $merged = $service->validateAndMerge($highs, $semis, [
            'next_action' => 'Prepare one PR for Alcohol this cycle with the suggested buy quantity below.',
            'ranked_high_item_ids' => [$id],
            'why_by_item_id' => [],
            'semi_next_action' => 'Prioritize Desk Organizer. Issue from stock as shown for that unit.',
        ]);

        $this->assertSame(
            'Prioritize Desk Organizer. Issue from stock as shown for that unit.',
            $merged['semi_next_action'],
        );

        $fallbackSemi = $service->validateAndMerge($highs, $semis, [
            'next_action' => 'Prepare one PR for Alcohol this cycle with the suggested buy quantity below.',
            'ranked_high_item_ids' => [$id],
            'why_by_item_id' => [],
            'semi_next_action' => 'Two sentences naming the top High items and the custodian next step.',
        ]);
        $this->assertSame(
            AiProcurementRecommenderService::SEMI_NEXT_ACTION_FALLBACK,
            $fallbackSemi['semi_next_action'],
        );
    }

    public function test_recommend_uses_valid_ollama_json(): void
    {
        $run = $this->makeRunWithHighs([
            ['name' => 'Alcohol', 'stock' => 4, 'suggested' => 16, 'usage' => 6.5],
            ['name' => 'Bond Paper', 'stock' => 8, 'suggested' => 32, 'usage' => 13.0],
        ]);

        $highs = $run->items->where('priority', 'High')->values();
        $ids = $highs->pluck('item_id')->map(fn ($id) => (int) $id)->all();

        $payload = json_encode([
            'next_action' => 'Prepare one PR for Alcohol first.',
            'ranked_high_item_ids' => [$ids[0], $ids[1]],
            'why_by_item_id' => [
                (string) $ids[0] => 'Stock 4 is below reorder 10 with thin cover.',
                (string) $ids[1] => 'Bond Paper needs restock before the next cycle.',
            ],
            'semi_next_action' => '',
        ], JSON_THROW_ON_ERROR);

        $expectedTemperature = max(0.0, min(1.0, (float) config('services.ollama.chat_temperature', 0.4)));

        $ollama = Mockery::mock(OllamaClient::class);
        $ollama->shouldReceive('isAvailable')->andReturn(true);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(function (string $system, string $user, array $options) use ($expectedTemperature): bool {
                return ($options['temperature'] ?? null) === $expectedTemperature
                    && str_contains($system, 'Vary the wording')
                    && str_contains($user, 'Phrasing style for this run')
                    && str_contains($user, 'high_consumables');
            })
            ->andReturn($payload);
        $this->app->instance(OllamaClient::class, $ollama);

        $result = app(AiProcurementRecommenderService::class)->recommend($run->fresh(['items']));

        $this->assertSame('ai', $result['source']);
        $this->assertStringContainsString('Prepare one PR for Alcohol first.', $result['next_action']);
        $this->assertStringContainsString('Focus the purchase request on', $result['next_action']);
        $this->assertSame([$ids[0], $ids[1]], $result['ranked_high_item_ids']);
        $this->assertSame(
            'Stock 4 is below reorder 10 with thin cover.',
            $result['why_by_item_id'][(string) $ids[0]],
        );
        $this->assertSame(
            'Bond Paper needs restock before the next cycle.',
            $result['why_by_item_id'][(string) $ids[1]],
        );
    }

    /**
     * @param  list<array{name: string, stock: int, suggested: int, usage: float, flags?: list<string>, spike_share?: float|null}>  $items
     */
    protected function makeRunWithHighs(array $items): AiProcurementRun
    {
        $run = AiProcurementRun::factory()->create(['status' => 'pending']);

        foreach ($items as $row) {
            $item = Item::factory()->create(['name' => $row['name'], 'reorder_level' => 10]);
            AiProcurementItem::query()->create([
                'run_id' => $run->id,
                'section' => 'urgent',
                'priority' => 'High',
                'item_name' => $row['name'],
                'item_id' => $item->id,
                'current_stock' => $row['stock'],
                'suggested_qty_min' => $row['suggested'],
                'months_cover' => 0.6,
                'avg_monthly_usage' => $row['usage'],
                'reason' => sprintf(
                    'Stock %d (reorder 10), forecast %.1f/mo, cover 0.6 months.',
                    $row['stock'],
                    $row['usage'],
                ),
                'insight_flags' => $row['flags'] ?? ['below_reorder', 'file_pr_soon'],
                'spike_share' => $row['spike_share'] ?? null,
                'include_in_request' => true,
            ]);
        }

        return $run->fresh(['items']);
    }
}
