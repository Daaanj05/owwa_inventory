<?php

namespace Tests\Feature;

use App\Filament\Pages\ProcurementAnalytics;
use App\Models\AiProcurementItem;
use App\Models\AiProcurementRun;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AiProcurementHelperService;
use App\Services\AiProcurementRecommendationService;
use App\Services\AiProcurementRunPdfExportService;
use App\Services\RagService;
use App\Support\InventoryCategoryOptions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

class AiProcurementHelperAndScrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_format_item_lines_includes_more_than_eight_items(): void
    {
        $items = [];
        for ($i = 1; $i <= 12; $i++) {
            $items[] = [
                'priority' => 'High',
                'item' => "Item {$i}",
                'stock' => $i,
                'reorder_level' => 5,
                'cover' => 0.5,
                'forecast' => 2.0,
                'suggested' => 10,
            ];
        }

        $lines = app(RagService::class)->formatItemLines($items);

        $this->assertStringContainsString('Item 1', $lines);
        $this->assertStringContainsString('Item 12', $lines);
        $this->assertSame(12, substr_count($lines, "\n") + 1);
    }

    public function test_collect_source_rows_uses_raised_source_limit_constant(): void
    {
        $this->assertSame(1000, AiProcurementRecommendationService::SOURCE_ROW_LIMIT);
    }

    public function test_load_more_at_risk_rows_returns_chunk_without_full_initial_payload(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        ItemCategory::factory()->create(['name' => 'Consumables']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        InventoryCategoryOptions::forgetCache();

        $this->actingAs($custodian);

        $component = Livewire::test(ProcurementAnalytics::class);

        $initial = $component->instance()->getAtRiskInitialClientRows();
        $this->assertLessThanOrEqual(25, count($initial));

        $chunk = $component->instance()->loadMoreAtRiskRows();
        $this->assertIsArray($chunk);
    }

    public function test_finalize_run_persists_deterministic_helpers_without_helper_job(): void
    {
        $run = AiProcurementRun::factory()->create(['status' => 'pending']);

        $rows = collect([(object) [
            'priority' => 'High',
            'item_name' => 'Alcohol',
            'item_id' => Item::factory()->create()->id,
            'office_name' => 'RWO IV-A',
            'office_id' => Office::factory()->create()->id,
            'current_stock' => 4,
            'reorder_level' => 10,
            'forecast_monthly_usage' => 6.5,
            'months_cover' => 0.6,
            'suggested_reorder_qty' => 16,
        ]]);

        app(AiProcurementRecommendationService::class)->finalizeRun(
            $run,
            "[[NEXT ACTION]]\nBuy High items.\n\n[[CONSUMABLES]]\n- **Alcohol** — stock 4 → buy 16\n\n[[SEMI-EXPENDABLE]]\nNone.",
            $rows,
            new Collection,
        );

        $helpers = $run->fresh()->helpers;
        $this->assertIsArray($helpers);
        $this->assertSame('Why these are flagged', $helpers['explain_high']['title'] ?? null);
        $this->assertSame('What to buy next', $helpers['next_pr_package']['title'] ?? null);
        $this->assertSame('Replace or Update Semi-Expendable Items', $helpers['semi_expendable_first']['title'] ?? null);
        $explainBody = (string) ($helpers['explain_high']['body'] ?? '');
        $this->assertStringContainsString('Alcohol', $explainBody);
        $this->assertStringContainsString('units/month', $explainBody);
        $this->assertStringContainsString('Below reorder', $explainBody);
        $this->assertStringContainsString(' · ', $explainBody);
        $this->assertStringContainsString('of 6.5 units/month', $explainBody);
        $this->assertStringNotContainsString(' / ', $explainBody);
    }

    public function test_page_shows_four_section_titles_across_two_slides(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $run = AiProcurementRun::factory()->create([
            'status' => 'pending',
            'created_by' => $custodian->id,
            'summary' => 'Buy High items.',
            'raw_response' => "[[NEXT ACTION]]\nBuy High items.\n\n[[CONSUMABLES]]\n- Alcohol\n\n[[SEMI-EXPENDABLE]]\nDesk Organizer",
            'helpers' => [
                'explain_high' => [
                    'title' => 'Why these are flagged',
                    'body' => '- **Alcohol** — below reorder / 6.5 units/month',
                ],
                'next_pr_package' => [
                    'title' => 'What to buy next',
                    'body' => '- **Alcohol**',
                ],
                'semi_expendable_first' => [
                    'title' => 'Replace or Update Semi-Expendable Items',
                    'body' => '- **Desk Organizer**',
                ],
            ],
        ]);

        AiProcurementItem::query()->create([
            'run_id' => $run->id,
            'section' => 'urgent',
            'priority' => 'High',
            'item_name' => 'Alcohol',
            'current_stock' => 4,
            'suggested_qty_min' => 16,
            'months_cover' => 0.6,
            'avg_monthly_usage' => 6.5,
            'reason' => 'Stock 4 (reorder 10), forecast 6.5/mo, cover 0.6 months.',
            'include_in_request' => true,
        ]);

        AiProcurementItem::query()->create([
            'run_id' => $run->id,
            'section' => 'replacement',
            'priority' => 'Medium',
            'item_name' => 'Desk Organizer',
            'property_number' => 'SPH-SEM-003',
            'eul_status' => 'Nearing expiry',
            'estimated_useful_life' => '60 months',
            'eul_days_remaining' => 120,
            'replacement_action' => 'issue_from_stock',
            'reason' => 'Issue from stock',
            'include_in_request' => false,
        ]);

        AiProcurementItem::query()->create([
            'run_id' => $run->id,
            'section' => 'replacement',
            'priority' => 'High',
            'item_name' => 'Paper Cutter',
            'property_number' => 'SPH-SEM-002',
            'eul_status' => 'Expired',
            'estimated_useful_life' => '60 months',
            'eul_days_remaining' => -10,
            'replacement_action' => 'awaiting_review',
            'reason' => 'Awaiting review',
            'include_in_request' => false,
        ]);

        $component = Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->set('lastAiRunId', $run->id)
            ->set('recommendation', (string) $run->raw_response)
            ->assertSee('Procurement Summary')
            ->assertSee('Consumable Summary')
            ->assertSee('Why these are flagged')
            ->assertSee('What to buy next')
            ->assertSee('1 of 2')
            ->assertSeeHtml('owwa-pa-rec-section owwa-pa-callout')
            ->assertDontSee('Prepare what to buy next')
            ->assertDontSee('Consumables to reorder')
            ->assertDontSee('Explain High items')
            ->assertDontSee('Replace now')
            ->assertDontSeeHtml('owwa-pr-ai-narrative');

        $mapped = $component->instance()->getRecommendationReplacementInitialRows();
        $desk = collect($mapped)->firstWhere('item_name', 'Desk Organizer');
        $paper = collect($mapped)->firstWhere('item_name', 'Paper Cutter');
        $this->assertSame('Issue from stock', $desk['next_step'] ?? null);
        $this->assertSame(
            AiProcurementHelperService::NEXT_STEP_AWAITING_UPDATE,
            $paper['next_step'] ?? null,
        );
        $this->assertSame('~4 months left', $desk['life_remaining_phrase'] ?? null);
        $this->assertSame('past EUL', $paper['life_remaining_phrase'] ?? null);
        $this->assertStringContainsString('EUL 60 months', (string) ($desk['semi_meta'] ?? ''));
        $this->assertStringContainsString('~4 months left', (string) ($desk['semi_meta'] ?? ''));

        $consumables = $component->instance()->getRecommendationReorderInitialRows();
        $alcohol = collect($consumables)->firstWhere('item_name', 'Alcohol');
        $why = (string) ($alcohol['why_explanation'] ?? '');
        $this->assertStringStartsWith('Below reorder', $why);
        $this->assertStringContainsString(' · ', $why);
        $this->assertStringContainsString('of 6.5 units/month', $why);
        $this->assertStringNotContainsString(' / ', $why);

        $component->call('setRecommendationSlide', 'replacement')
            ->assertSee('Replace or Update Semi-Expendable Items')
            ->assertSee(AiProcurementHelperService::NEXT_STEP_AWAITING_UPDATE)
            ->assertSee('Issue from stock')
            ->assertSee('EUL 60 months')
            ->assertSee('~4 months left')
            ->assertSee('past EUL');
    }

    public function test_recommendation_lazy_chunks_return_page_sized_batches(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $run = AiProcurementRun::factory()->create([
            'status' => 'pending',
            'created_by' => $custodian->id,
        ]);

        for ($i = 1; $i <= 30; $i++) {
            AiProcurementItem::query()->create([
                'run_id' => $run->id,
                'section' => 'urgent',
                'priority' => $i <= 5 ? 'High' : 'Medium',
                'item_name' => "Consumable {$i}",
                'current_stock' => $i,
                'suggested_qty_min' => $i + 10,
                'months_cover' => 0.5,
                'avg_monthly_usage' => 2.0,
                'include_in_request' => true,
            ]);
        }

        for ($i = 1; $i <= 30; $i++) {
            AiProcurementItem::query()->create([
                'run_id' => $run->id,
                'section' => 'replacement',
                'priority' => 'Medium',
                'item_name' => "Semi {$i}",
                'property_number' => sprintf('SPH-SEM-%03d', $i),
                'eul_status' => 'Nearing expiry',
                'replacement_action' => 'awaiting_review',
                'reason' => 'Awaiting review',
                'include_in_request' => false,
            ]);
        }

        $component = Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->set('lastAiRunId', $run->id)
            ->set('recommendation', 'Recommendation ready.');

        $allReorder = $component->instance()->getRecommendationReorderRows();
        $this->assertCount(30, $allReorder);

        $initialReorder = $component->instance()->getRecommendationReorderInitialRows();
        $this->assertCount(25, $initialReorder);
        $this->assertSame(
            $allReorder->take(25)->pluck('item_name')->all(),
            collect($initialReorder)->pluck('item_name')->all(),
        );

        $moreReorder = $component->instance()->loadMoreRecommendationReorderRows();
        $this->assertCount(5, $moreReorder);
        $this->assertSame(
            $allReorder->slice(25)->pluck('item_name')->values()->all(),
            collect($moreReorder)->pluck('item_name')->all(),
        );

        $allSemi = $component->instance()->getRecommendationReplacementRows();
        $this->assertCount(30, $allSemi);

        $initialSemi = $component->instance()->getRecommendationReplacementInitialRows();
        $this->assertCount(25, $initialSemi);

        $moreSemi = $component->instance()->loadMoreRecommendationReplacementRows();
        $this->assertCount(5, $moreSemi);
        $this->assertSame(
            AiProcurementHelperService::NEXT_STEP_AWAITING_UPDATE,
            $moreSemi[0]['next_step'] ?? null,
        );
        $this->assertSame(
            $allSemi->slice(25)->pluck('item_name')->values()->all(),
            collect($moreSemi)->pluck('item_name')->all(),
        );
    }

    public function test_semi_helper_includes_awaiting_for_update_label(): void
    {
        $run = AiProcurementRun::factory()->create(['status' => 'pending']);
        AiProcurementItem::query()->create([
            'run_id' => $run->id,
            'section' => 'replacement',
            'priority' => 'High',
            'item_name' => 'Paper Cutter',
            'property_number' => 'SPH-SEM-002',
            'eul_status' => 'Expired',
            'replacement_action' => 'awaiting_review',
            'reason' => 'Awaiting review',
            'include_in_request' => false,
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

        app(AiProcurementHelperService::class)->persistDeterministicHelpers($run);

        $helpers = $run->fresh()->helpers;
        $body = $helpers['semi_expendable_first']['body'] ?? '';
        $this->assertSame('Replace or Update Semi-Expendable Items', $helpers['semi_expendable_first']['title'] ?? null);
        $this->assertStringContainsString('Desk Organizer', $body);
        $this->assertStringContainsString('Paper Cutter', $body);
        $this->assertStringContainsString(AiProcurementHelperService::NEXT_STEP_AWAITING_UPDATE, $body);
        $this->assertStringContainsString('Nearing expiry → Issue from stock', $body);
    }

    public function test_pdf_export_includes_recommendation_memo(): void
    {
        $run = AiProcurementRun::factory()->create([
            'status' => 'pending',
            'summary' => 'Short summary',
            'raw_response' => "[[NEXT ACTION]]\nOpen one PR for High items.\n\n[[CONSUMABLES]]\nBuy Alcohol.\n\n[[SEMI-EXPENDABLE]]\nNone due.",
            'policy_snippets' => [
                ['source' => 'policy', 'content' => 'Prioritize High when cover is under one month.'],
            ],
            'helpers' => [
                'next_pr_package' => [
                    'title' => 'What to buy next',
                    'body' => 'Include Alcohol in the next request.',
                ],
            ],
        ]);

        $item = Item::factory()->create();
        AiProcurementItem::query()->create([
            'run_id' => $run->id,
            'section' => 'urgent',
            'priority' => 'High',
            'item_name' => $item->name,
            'item_id' => $item->id,
            'current_stock' => 1,
            'suggested_qty_min' => 5,
            'include_in_request' => true,
        ]);

        $response = app(AiProcurementRunPdfExportService::class)->downloadRun($run->fresh(['items', 'creator']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('pdf', strtolower((string) $response->headers->get('content-type')));
    }
}
