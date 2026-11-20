<?php

namespace Tests\Feature;

use App\Filament\Pages\ProcurementAnalytics;
use App\Filament\Widgets\CoverageOverviewWidget;
use App\Jobs\GenerateAiProcurementRecommendationJob;
use App\Models\AiProcurementRun;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AiProcurementRecommendationService;
use App\Services\RagService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ProcurementAnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_filter_reduces_at_risk_rows_and_kpi_pairs(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $categoryA = ItemCategory::factory()->create(['name' => 'Consumables A']);
        $categoryB = ItemCategory::factory()->create(['name' => 'Consumables B']);

        $itemA = Item::factory()->create(['item_category_id' => $categoryA->id, 'reorder_level' => 2]);
        $itemB = Item::factory()->create(['item_category_id' => $categoryB->id, 'reorder_level' => 2]);

        $this->seedMonthlyIssuances($itemA->id, $office->id, 10, 6);
        $this->seedMonthlyIssuances($itemB->id, $office->id, 10, 6);
        $this->createAcquisition($itemA->id, $office->id, 5 + 60);
        $this->createAcquisition($itemB->id, $office->id, 5 + 60);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $component = Livewire::actingAs($custodian)->test(ProcurementAnalytics::class);
        $component->assertDontSee('All (excl. PPE)');
        $allCount = $component->instance()->queryAtRiskRows()->count();
        $this->assertGreaterThanOrEqual(2, $allCount);

        $previewCount = $component->instance()->getAtRiskPreviewRows()->count();
        $this->assertSame($allCount, $previewCount);

        $widget = Livewire::actingAs($custodian)->test(CoverageOverviewWidget::class, [
            'from' => $component->get('from'),
            'to' => $component->get('to'),
            'categoryId' => (string) $categoryA->id,
        ]);

        $widget->assertSee('Total at-risk');
        $widget->assertSee('1');
    }

    public function test_stockout_view_tab_filters_rows_to_two_month_cover_or_less(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $fastItem = Item::factory()->create(['item_category_id' => $category->id, 'reorder_level' => 2]);
        $slowItem = Item::factory()->create(['item_category_id' => $category->id, 'reorder_level' => 2]);

        $this->seedMonthlyIssuances($fastItem->id, $office->id, 20, 6);
        $this->seedMonthlyIssuances($slowItem->id, $office->id, 5, 6);
        $this->createAcquisition($fastItem->id, $office->id, 10 + 120);
        $this->createAcquisition($slowItem->id, $office->id, 20 + 30);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->call('setAtRiskView', 'stockouts')
            ->tap(function ($component) use ($fastItem, $slowItem): void {
                $rows = $component->instance()->getAtRiskPreviewRows();
                $this->assertTrue($rows->every(
                    fn ($row): bool => $row->months_cover !== null && (float) $row->months_cover <= 2.0
                ));
                $itemIds = $rows->pluck('item_id')->all();
                $this->assertContains($fastItem->id, $itemIds);
                $this->assertNotContains($slowItem->id, $itemIds);
            });
    }

    public function test_stockout_tab_shows_rows_for_fixed_twelve_month_window(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id, 'reorder_level' => 2]);

        $this->seedMonthlyIssuances($item->id, $office->id, 20, 12);
        $this->createAcquisition($item->id, $office->id, 10 + 240);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->call('setAtRiskView', 'stockouts')
            ->tap(function ($component): void {
                $this->assertGreaterThan(0, $component->instance()->getStockoutPreviewCount());
            });
    }

    public function test_stockout_tab_excludes_items_without_issuance_history(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'reorder_level' => 10,
        ]);

        $this->createAcquisition($item->id, $office->id, 4);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->call('setAtRiskView', 'stockouts')
            ->tap(function ($component) use ($item): void {
                $rows = $component->instance()->getAtRiskPreviewRows();
                $this->assertNull($rows->first(fn ($row) => $row->item_id === $item->id));
            });
    }

    public function test_at_risk_rows_open_as_a_window_and_sort_by_category_priority_then_name(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $adhesives = ItemCategory::factory()->create(['name' => 'Adhesives']);
        $brushes = ItemCategory::factory()->create(['name' => 'Brushes']);

        $apple = Item::factory()->create([
            'name' => 'Apple Tape',
            'item_category_id' => $adhesives->id,
            'reorder_level' => 2,
        ]);
        $mango = Item::factory()->create([
            'name' => 'Mango Tape',
            'item_category_id' => $adhesives->id,
            'reorder_level' => 2,
        ]);
        $zebra = Item::factory()->create([
            'name' => 'Zebra Brush',
            'item_category_id' => $brushes->id,
            'reorder_level' => 2,
        ]);
        $alpha = Item::factory()->create([
            'name' => 'Alpha Brush',
            'item_category_id' => $brushes->id,
            'reorder_level' => 2,
        ]);

        foreach ([$apple, $zebra] as $item) {
            $this->seedMonthlyIssuances($item->id, $office->id, 10, 6);
            $this->createAcquisition($item->id, $office->id, 5 + 60);
        }

        foreach ([$mango, $alpha] as $item) {
            $this->seedMonthlyIssuances($item->id, $office->id, 10, 6);
            $this->createAcquisition($item->id, $office->id, 25 + 60);
        }

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $component = Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->set('atRiskVisibleLimit', 1);

        $this->assertSame(4, $component->instance()->getAtRiskPreviewCount());
        $this->assertCount(1, $component->instance()->getAtRiskPreviewRows());
        $this->assertSame('Apple Tape', $component->instance()->getAtRiskPreviewRows()->first()->item_name);
        $component->assertSee('All at-risk (4)');
        $component->assertSee('atRiskPreviewTable');
        $component->assertSee('Apple Tape');
        $component->assertDontSee('wire:click="setAtRiskView');
        $component->assertDontSee('wire:intersect="loadMoreAtRiskRows"');

        $component->call('loadMoreAtRiskRows');

        $this->assertSame(
            ['Apple Tape', 'Mango Tape', 'Zebra Brush', 'Alpha Brush'],
            $component->instance()->getAtRiskPreviewRows()->pluck('item_name')->all(),
        );
        $component->assertSee('All at-risk (4)');
    }

    public function test_period_context_uses_fixed_twelve_month_label(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $component = Livewire::actingAs($custodian)->test(ProcurementAnalytics::class);

        $this->assertSame(
            'Last 12 months through today',
            $component->instance()->getPeriodContext()['label'],
        );
        $this->assertStringContainsString(
            'Suggestions use the last 12 months through today.',
            $component->html(),
        );
    }

    public function test_build_procurement_action_summary_headline_counts_high_and_medium(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $highItem = Item::factory()->create(['item_category_id' => $category->id, 'reorder_level' => 10]);
        $mediumItem = Item::factory()->create(['item_category_id' => $category->id, 'reorder_level' => 2]);

        $this->seedMonthlyIssuances($highItem->id, $office->id, 10, 6);
        $this->seedMonthlyIssuances($mediumItem->id, $office->id, 10, 6);
        $this->createAcquisition($highItem->id, $office->id, 5 + 60);
        $this->createAcquisition($mediumItem->id, $office->id, 25 + 60);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->tap(function ($component): void {
                $summary = $component->instance()->buildProcurementActionSummary(
                    $component->instance()->queryAtRiskRows()
                );
                $this->assertStringContainsString('High', $summary['headline']);
                $this->assertNotEmpty($summary['priority_actions']);
            });
    }

    public function test_procurement_summary_hidden_before_generate(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id, 'reorder_level' => 10]);

        $this->seedMonthlyIssuances($item->id, $office->id, 10, 6);
        $this->createAcquisition($item->id, $office->id, 5 + 60);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->assertSet('recommendation', null)
            ->assertSee('Generate a recommendation from the consumable reorder list and the replacement-due list.')
            ->assertDontSee('AI narrative needs Ollama');
    }

    public function test_format_ai_narrative_strips_sentence_scaffolding_and_wrapping_quotes(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email_verified_at' => now(),
        ]);

        $raw = '"Sentence 2 must be: "Given these high-risk at-risk pairs, it is recommended to replenish Note Pad Sign Here (5)."';

        $formatted = Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->instance()
            ->formatAiNarrativeMarkdown($raw);

        $this->assertSame(
            'Given these high-risk at-risk pairs, it is recommended to replenish Note Pad Sign Here (5).',
            $formatted,
        );
        $this->assertStringNotContainsString('Sentence', $formatted);
        $this->assertDoesNotMatchRegularExpression('/^["“]/', $formatted);
        $this->assertDoesNotMatchRegularExpression('/["”]$/', $formatted);
    }

    public function test_split_slide_narratives_does_not_leak_markers_when_consumables_empty(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email_verified_at' => now(),
        ]);

        $raw = <<<'TXT'
[[NEXT ACTION]]
Issue from stock first.

[[CONSUMABLES]]

[[SEMI-EXPENDABLE]]
Paper Cutter (SPH-002) — Expired
TXT;

        $page = Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->instance();

        $parts = $page->splitSlideNarratives($raw);
        $this->assertSame('Issue from stock first.', $parts['consumables']);
        $this->assertStringContainsString('Paper Cutter', $parts['replacement']);
        $this->assertStringNotContainsString('[[CONSUMABLES]]', $parts['consumables']);
        $this->assertStringNotContainsString('[[SEMI-EXPENDABLE]]', $parts['replacement']);

        $page->recommendation = $raw."\n\n### table";
        $reorders = $page->recommendationNarrativeForSlide('reorders');
        $this->assertStringNotContainsString('[[', $reorders);
        $this->assertStringNotContainsString('Paper Cutter', $reorders);
        $this->assertStringContainsString('Issue from stock first.', $reorders);

        $formatted = $page->formatAiNarrativeMarkdown(
            "\" Issue from stock\n\n[[CONSUMABLES]]\nAlcohol 70% → buy 16 — 3 weeks"
        );
        $this->assertStringNotContainsString('[[CONSUMABLES]]', $formatted);
        $this->assertStringContainsString('- Alcohol 70% → buy 16 — 3 weeks', $formatted);
        $this->assertDoesNotMatchRegularExpression('/^["“]/', $formatted);
    }

    public function test_procurement_summary_shown_after_generate(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id, 'reorder_level' => 10]);

        $this->seedMonthlyIssuances($item->id, $office->id, 10, 6);
        $this->createAcquisition($item->id, $office->id, 5 + 60);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->mock(\App\Services\RagService::class, function ($mock): void {
            $mock->shouldReceive('generateNarrativeSummary')->once()->andReturn(null);
        });

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->call('generateAiRecommendation')
            ->assertSet('aiNarrativeUnavailable', true)
            ->assertSet('processingRunId', null)
            ->assertSee('AI narrative needs Ollama')
            ->assertSee('Saved recommendation')
            ->assertSee('Consumables to Buy')
            ->assertDontSee('AI recommendation unavailable. Review the lists above.');
    }

    public function test_generate_dispatches_background_job_when_queue_is_database(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id, 'reorder_level' => 10]);

        $this->seedMonthlyIssuances($item->id, $office->id, 10, 6);
        $this->createAcquisition($item->id, $office->id, 5 + 60);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        config(['queue.default' => 'database']);
        Queue::fake();

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->call('generateAiRecommendation')
            ->assertSet('loading', true)
            ->assertNotSet('processingRunId', null);

        Queue::assertPushed(GenerateAiProcurementRecommendationJob::class, function (GenerateAiProcurementRecommendationJob $job): bool {
            return $job->queue === 'ai';
        });

        $run = AiProcurementRun::query()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('processing', $run->status);
    }

    public function test_recommendation_job_completes_run_to_draft(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id, 'reorder_level' => 10]);

        $this->seedMonthlyIssuances($item->id, $office->id, 10, 6);
        $this->createAcquisition($item->id, $office->id, 5 + 60);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->mock(RagService::class, function ($mock): void {
            $mock->shouldReceive('generateNarrativeSummary')->once()->andReturn([
                'narrative' => 'Reorder high-priority toner immediately.',
                'policy_snippets' => [],
            ]);
        });

        $run = AiProcurementRun::create([
            'ran_at' => now(),
            'period_from' => now()->subMonths(11)->startOfMonth()->toDateString(),
            'period_to' => now()->endOfMonth()->toDateString(),
            'status' => 'processing',
            'created_by' => $custodian->id,
        ]);

        $job = new GenerateAiProcurementRecommendationJob(
            runId: $run->id,
            periodFrom: $run->period_from->toDateString(),
            periodTo: $run->period_to->toDateString(),
            categoryId: null,
            officeIds: [$office->id],
        );

        $job->handle(app(AiProcurementRecommendationService::class));

        $run->refresh();
        $this->assertSame('pending', $run->status);
        $this->assertStringContainsString('Reorder high-priority toner', (string) $run->raw_response);
        $this->assertGreaterThan(0, $run->items()->count());
    }

    public function test_recommendation_job_marks_run_failed_when_rag_throws(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->mock(RagService::class, function ($mock): void {
            $mock->shouldReceive('generateNarrativeSummary')->once()->andThrow(new \RuntimeException('Connection refused'));
        });

        $run = AiProcurementRun::create([
            'ran_at' => now(),
            'period_from' => now()->subMonths(11)->startOfMonth()->toDateString(),
            'period_to' => now()->endOfMonth()->toDateString(),
            'status' => 'processing',
            'created_by' => $custodian->id,
        ]);

        $job = new GenerateAiProcurementRecommendationJob(
            runId: $run->id,
            periodFrom: $run->period_from->toDateString(),
            periodTo: $run->period_to->toDateString(),
            categoryId: null,
            officeIds: [$office->id],
        );

        $job->handle(app(AiProcurementRecommendationService::class));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Cannot connect', (string) $run->error_message);
    }

    public function test_sync_processing_run_hydrates_narrative_when_run_completes(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $run = AiProcurementRun::create([
            'ran_at' => now(),
            'period_from' => now()->subMonths(11)->startOfMonth()->toDateString(),
            'period_to' => now()->endOfMonth()->toDateString(),
            'status' => 'pending',
            'raw_response' => "Reorder paper supplies now.\n\n| Priority | Item |",
            'created_by' => $custodian->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->set('processingRunId', $run->id)
            ->set('loading', true)
            ->call('syncProcessingRun')
            ->assertSet('processingRunId', null)
            ->assertSet('loading', false)
            ->assertSet('recommendation', 'Reorder paper supplies now.');
    }

    public function test_procurement_summary_cleared_on_filter_change(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id, 'reorder_level' => 10]);

        $this->seedMonthlyIssuances($item->id, $office->id, 10, 6);
        $this->createAcquisition($item->id, $office->id, 5 + 60);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->mock(\App\Services\RagService::class, function ($mock): void {
            $mock->shouldReceive('generateNarrativeSummary')->once()->andReturn(null);
        });

        $component = Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->call('generateAiRecommendation');

        $this->assertNotNull($component->get('lastAiRunId'));

        $component
            ->call('setAtRiskView', 'stockouts')
            ->assertSet('recommendation', null)
            ->assertSee('Generate a recommendation from the consumable reorder list and the replacement-due list.')
            ->assertDontSee('AI narrative needs Ollama');
    }

    public function test_default_scope_excludes_ppe_and_category_dropdown_omits_ppe(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $ppe = ItemCategory::query()->firstOrCreate(
            ['name' => 'PPE'],
            ['description' => 'Property, plant and equipment'],
        );

        $consumableItem = Item::factory()->create(['item_category_id' => $consumables->id, 'reorder_level' => 2]);
        $ppeItem = Item::factory()->create(['item_category_id' => $ppe->id, 'reorder_level' => 2]);

        $this->seedMonthlyIssuances($consumableItem->id, $office->id, 10, 6);
        $this->seedMonthlyIssuances($ppeItem->id, $office->id, 10, 6);
        $this->createAcquisition($consumableItem->id, $office->id, 5 + 60);
        $this->createAcquisition($ppeItem->id, $office->id, 5 + 60);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $component = Livewire::actingAs($custodian)->test(ProcurementAnalytics::class);

        $categories = \App\Support\InventoryCategoryOptions::procurementAnalyticsCategories();
        $this->assertTrue($categories->contains('id', $consumables->id));
        $this->assertFalse($categories->contains('id', $ppe->id));
        $component->assertDontSee('All (excl. PPE)');

        $itemIds = $component->instance()->queryAtRiskRows()->pluck('item_id')->all();
        $this->assertContains($consumableItem->id, $itemIds);
        $this->assertNotContains($ppeItem->id, $itemIds);

        $component->set('categoryId', (string) $ppe->id);
        $this->assertSame('', $component->get('categoryId'));
    }

    public function test_eul_panel_lists_nearing_semi_expendable_and_hides_for_consumables_filter(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        $semiItem = Item::factory()->create([
            'item_category_id' => $semi->id,
            'name' => 'Office Chair',
            'estimated_useful_life' => '5 years',
        ]);
        $consumableItem = Item::factory()->create([
            'item_category_id' => $consumables->id,
            'name' => 'Bond Paper',
        ]);

        $issuedTo = User::factory()->create(['office_id' => $office->id]);

        DB::table('issuances')->insert([
            'reference_code' => 'ISS-EUL-SEMI-'.uniqid(),
            'item_id' => $semiItem->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'issuance_date' => now()->subYears(4)->toDateString(),
            'estimated_useful_life' => '5 years',
            'eul_expires_at' => now()->addDays(30)->toDateString(),
            'property_number' => 'SEMI-001',
            'issued_to' => $issuedTo->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('issuances')->insert([
            'reference_code' => 'ISS-EUL-CONS-'.uniqid(),
            'item_id' => $consumableItem->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'issuance_date' => now()->subMonth()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $component = Livewire::actingAs($custodian)->test(ProcurementAnalytics::class);

        $this->assertTrue($component->instance()->shouldShowEulPanel());
        $eulRows = $component->instance()->getEulReviewRows();
        $this->assertCount(1, $eulRows);
        $this->assertSame('Office Chair', $eulRows->first()->item_name);
        $this->assertSame('nearing', $eulRows->first()->status);
        $this->assertSame($office->name, $eulRows->first()->office_name);
        Livewire::actingAs($custodian)
            ->withQueryParams(['analyticsSlide' => 'replacement'])
            ->test(ProcurementAnalytics::class)
            ->assertSet('analyticsSlide', 'replacement')
            ->assertSee('Replacement Due - Semi-Expendable Only');

        $component->call('setAnalyticsSlide', 'replacement');
        $component->assertSee('Replacement Due - Semi-Expendable Only');
        $component->assertSee('Office Chair');
        $component->assertSee($office->name);
        $component->assertSee('Nearing expiry');
        $component->assertDontSee('Awaiting review');
        $component->assertDontSee('tableAction=view');

        $this->assertTrue($component->instance()->shouldShowReorderSlide());
        $this->assertCount(1, $component->instance()->getEulReviewRows());
    }

    public function test_mount_restores_processing_run_into_summary_state(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $run = AiProcurementRun::query()->create([
            'ran_at' => now(),
            'period_from' => now()->subMonths(11)->startOfMonth()->toDateString(),
            'period_to' => now()->endOfMonth()->toDateString(),
            'status' => 'processing',
            'created_by' => $custodian->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->assertSet('processingRunId', $run->id)
            ->assertSet('lastAiRunId', $run->id)
            ->assertSet('loading', true)
            ->assertDontSee('AI offline');
    }

    public function test_mount_does_not_restore_completed_draft_without_pending_flag(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        AiProcurementRun::query()->create([
            'ran_at' => now(),
            'period_from' => now()->subMonths(11)->startOfMonth()->toDateString(),
            'period_to' => now()->endOfMonth()->toDateString(),
            'status' => 'pending',
            'raw_response' => "Stock gaps need attention now.\n\n| Priority | Item |",
            'created_by' => $custodian->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->assertSet('processingRunId', null)
            ->assertSet('loading', false)
            ->assertSet('lastAiRunId', null)
            ->assertSet('recommendation', null)
            ->assertDontSee('AI offline')
            ->assertSee('Generate a recommendation from the consumable reorder list and the replacement-due list.');
    }

    public function test_mount_restores_completed_draft_until_done(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $run = AiProcurementRun::query()->create([
            'ran_at' => now(),
            'period_from' => now()->subMonths(11)->startOfMonth()->toDateString(),
            'period_to' => now()->endOfMonth()->toDateString(),
            'status' => 'pending',
            'raw_response' => "Stock gaps need attention now.\n\n| Priority | Item |",
            'created_by' => $custodian->id,
        ]);

        \App\Support\AiProcurementSummaryRestore::remember($custodian->id, $run->id);

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->assertSet('processingRunId', null)
            ->assertSet('loading', false)
            ->assertSet('lastAiRunId', $run->id)
            ->assertSet('recommendation', 'Stock gaps need attention now.')
            ->assertSee('Stock gaps need attention now.')
            ->assertSee('Done');

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->assertSet('lastAiRunId', $run->id)
            ->assertSet('recommendation', 'Stock gaps need attention now.')
            ->call('dismissRecommendation')
            ->assertSet('lastAiRunId', null)
            ->assertSet('recommendation', null)
            ->assertSee('Generate a recommendation from the consumable reorder list and the replacement-due list.');

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->assertSet('lastAiRunId', null)
            ->assertSet('recommendation', null)
            ->assertSee('Generate a recommendation from the consumable reorder list and the replacement-due list.');
    }

    public function test_regenerate_clears_visible_recommendation_before_loading(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $run = AiProcurementRun::query()->create([
            'ran_at' => now(),
            'period_from' => now()->subMonths(11)->startOfMonth()->toDateString(),
            'period_to' => now()->endOfMonth()->toDateString(),
            'status' => 'pending',
            'raw_response' => "Prioritize Alcohol first for this cycle.\n\n| Priority | Item |",
            'created_by' => $custodian->id,
        ]);

        \App\Support\AiProcurementSummaryRestore::remember($custodian->id, $run->id);

        config(['queue.default' => 'database']);
        Queue::fake();

        Livewire::actingAs($custodian)
            ->test(ProcurementAnalytics::class)
            ->assertSet('lastAiRunId', $run->id)
            ->assertSet('recommendation', 'Prioritize Alcohol first for this cycle.')
            ->assertSee('Done')
            ->call('generateAiRecommendation')
            ->assertSet('loading', true)
            ->assertSet('recommendation', null)
            ->assertSet('lastAiRunId', fn ($id): bool => $id !== null && (int) $id !== (int) $run->id)
            ->assertSee('Generating recommendation…')
            ->assertSeeHtml('pa-summary-loading-')
            ->assertDontSeeHtml('owwa-pa-ai-recommendation');

        Queue::assertPushed(GenerateAiProcurementRecommendationJob::class);
        $this->assertNull(\App\Support\AiProcurementSummaryRestore::active($custodian->id));
    }

    public function test_mount_ignores_ai_run_query_and_keeps_active_cache_until_done(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $run = AiProcurementRun::query()->create([
            'ran_at' => now(),
            'period_from' => now()->subMonths(11)->startOfMonth()->toDateString(),
            'period_to' => now()->endOfMonth()->toDateString(),
            'status' => 'pending',
            'raw_response' => "View-result narrative ready.\n\n| Priority | Item |",
            'created_by' => $custodian->id,
        ]);

        $resultUrl = ProcurementAnalytics::resultUrl($run->id);
        $this->assertStringNotContainsString('ai_run=', $resultUrl);
        $this->assertStringNotContainsString('#procurement-summary', $resultUrl);

        Livewire::actingAs($custodian)
            ->withQueryParams(['ai_run' => $run->id])
            ->test(ProcurementAnalytics::class)
            ->assertSet('lastAiRunId', null)
            ->assertSet('recommendation', null)
            ->assertSee('Generate a recommendation from the consumable reorder list and the replacement-due list.');

        \App\Support\AiProcurementSummaryRestore::remember($custodian->id, $run->id);

        Livewire::actingAs($custodian)
            ->withQueryParams([])
            ->test(ProcurementAnalytics::class)
            ->assertSet('lastAiRunId', $run->id)
            ->assertSet('recommendation', 'View-result narrative ready.')
            ->assertSee('Procurement Summary')
            ->assertDontSee('View-result narrative ready.')
            ->assertSeeHtml('owwa-pa-rec-section owwa-pa-callout');

        Livewire::actingAs($custodian)
            ->withQueryParams([])
            ->test(ProcurementAnalytics::class)
            ->assertSet('lastAiRunId', $run->id)
            ->assertSet('recommendation', 'View-result narrative ready.')
            ->call('dismissRecommendation')
            ->assertSet('lastAiRunId', null)
            ->assertSet('recommendation', null)
            ->assertSee('Generate a recommendation from the consumable reorder list and the replacement-due list.');
    }

    public function test_suggested_reorder_export_downloads_a_pdf(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $component = Livewire::actingAs($custodian)->test(ProcurementAnalytics::class);
        $component->assertSee('Export PDF');
        $component->assertDontSee('Export CSV');

        $response = $component->instance()->exportAtRiskPdf();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('content-disposition'));

        ob_start();
        $response->sendContent();
        $body = ob_get_clean();

        $this->assertStringStartsWith('%PDF', $body);
        $this->assertStringContainsString('SuggestedReorders-', (string) $response->headers->get('content-disposition'));

        $stockoutExport = $component->instance()->exportAtRiskPdf('stockouts');
        $this->assertSame('application/pdf', $stockoutExport->headers->get('content-type'));
        $this->assertStringContainsString('SuggestedReorders-', (string) $stockoutExport->headers->get('content-disposition'));

        $component->set('analyticsSlide', 'replacement');
        $replacement = $component->instance()->exportAtRiskPdf();
        $this->assertSame('application/pdf', $replacement->headers->get('content-type'));
        $this->assertStringContainsString('ReplacementDue-', (string) $replacement->headers->get('content-disposition'));

        ob_start();
        $replacement->sendContent();
        $replacementBody = ob_get_clean();

        $this->assertStringStartsWith('%PDF', $replacementBody);
    }

    public function test_suggested_reorder_export_uses_requested_tab_view(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $fastItem = Item::factory()->create(['item_category_id' => $category->id, 'reorder_level' => 2]);
        $slowItem = Item::factory()->create(['item_category_id' => $category->id, 'reorder_level' => 2]);

        $this->seedMonthlyIssuances($fastItem->id, $office->id, 20, 6);
        $this->seedMonthlyIssuances($slowItem->id, $office->id, 5, 6);
        $this->createAcquisition($fastItem->id, $office->id, 10 + 120);
        $this->createAcquisition($slowItem->id, $office->id, 20 + 30);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $component = Livewire::actingAs($custodian)->test(ProcurementAnalytics::class);
        $page = $component->instance();

        $allRows = $page->queryAtRiskRows();
        $this->assertTrue($allRows->contains(fn ($row): bool => (int) $row->item_id === (int) $slowItem->id));

        $component->call('setAtRiskView', 'stockouts');
        $stockoutRows = $component->instance()->getAtRiskPreviewRows();
        $this->assertTrue($stockoutRows->every(
            fn ($row): bool => $row->months_cover !== null && (float) $row->months_cover <= 2.0
        ));
        $this->assertTrue($stockoutRows->contains(fn ($row): bool => (int) $row->item_id === (int) $fastItem->id));
        $this->assertFalse($stockoutRows->contains(fn ($row): bool => (int) $row->item_id === (int) $slowItem->id));

        $response = $page->exportAtRiskPdf('stockouts');
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        $allExport = $page->exportAtRiskPdf('all');
        $this->assertSame('application/pdf', $allExport->headers->get('content-type'));
    }

    public function test_how_this_works_is_a_spotlight_tour(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $html = Livewire::actingAs($custodian)->test(ProcurementAnalytics::class)->html();

        $this->assertStringContainsString('procurementSpotlightTour', $html);
        $this->assertStringContainsString('data-tour="period"', $html);
        $this->assertStringContainsString('data-tour="kpis"', $html);
        $this->assertStringContainsString('data-tour="reorders"', $html);
        $this->assertStringContainsString('data-tour="filters"', $html);
        $this->assertStringContainsString('data-tour="useful-life"', $html);
        $this->assertStringContainsString('data-tour="export"', $html);
        $this->assertStringContainsString('data-tour="generate"', $html);
        $this->assertStringContainsString('pa-tour-slide', $html);
        $this->assertStringNotContainsString('<details class="owwa-pa-how-it-works"', $html);
        $this->assertStringContainsString('window.procurementSpotlightTour', view('filament.partials.procurement-spotlight-tour')->render());
        $this->assertStringContainsString('This builds a recommendation from the consumable reorder list', view('filament.partials.procurement-spotlight-tour')->render());
    }

    protected function seedMonthlyIssuances(int $itemId, int $officeId, int $monthlyQty, int $months): void
    {
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i)->startOfMonth()->toDateString();
            DB::table('issuances')->insert([
                'reference_code' => 'ISS-TEST-'.$itemId.'-'.$officeId.'-'.$i.'-'.uniqid(),
                'item_id' => $itemId,
                'office_id' => $officeId,
                'quantity' => $monthlyQty,
                'issuance_date' => $date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
}
