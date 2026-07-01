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
        $allCount = $component->instance()->queryAtRiskRows()->count();
        $this->assertGreaterThanOrEqual(2, $allCount);

        $component->set('categoryId', (string) $categoryA->id);
        $filteredCount = $component->instance()->getAtRiskPreviewRows()->count();
        $this->assertSame(1, $filteredCount);
        $this->assertGreaterThan($filteredCount, $allCount);

        $widget = Livewire::actingAs($custodian)->test(CoverageOverviewWidget::class, [
            'from' => $component->get('from'),
            'to' => $component->get('to'),
            'categoryId' => (string) $categoryA->id,
        ]);

        $widget->assertSee('Total at-risk');
        $widget->assertSee((string) $filteredCount);
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
                $this->assertTrue($rows->every(function ($row): bool {
                    if ($row->priority === 'High' && ! ($row->has_recent_usage ?? true)) {
                        return true;
                    }

                    return $row->months_cover !== null && (float) $row->months_cover <= 2.0;
                }));
                $itemIds = $rows->pluck('item_id')->all();
                $this->assertContains($fastItem->id, $itemIds);
                $this->assertNotContains($slowItem->id, $itemIds);
            });
    }

    public function test_stockout_tab_shows_rows_on_three_month_preset(): void
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
            ->call('applyPeriodPreset', '3m')
            ->call('setAtRiskView', 'stockouts')
            ->tap(function ($component): void {
                $this->assertGreaterThan(0, $component->instance()->getStockoutPreviewCount());
            });
    }

    public function test_stockout_tab_includes_high_priority_without_recent_usage(): void
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
                $match = $rows->first(fn ($row) => $row->item_id === $item->id);
                $this->assertNotNull($match);
                $this->assertFalse($match->has_recent_usage);
            });
    }

    public function test_active_period_preset_matches_applied_preset(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $component = Livewire::actingAs($custodian)->test(ProcurementAnalytics::class);

        $component->call('applyPeriodPreset', '3m');
        $this->assertSame('3m', $component->instance()->getActivePeriodPreset());

        $component->set('from', now()->subMonths(4)->startOfMonth()->toDateString());
        $this->assertNull($component->instance()->getActivePeriodPreset());
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
            ->assertSet('actionSummary', null)
            ->assertSee('Generate a summary from the current at-risk table')
            ->assertDontSee('High priority');
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
            ->assertSet('actionSummary.headline', fn (?string $headline): bool => filled($headline) && str_contains($headline, 'at-risk'))
            ->assertSee('High priority')
            ->assertSee('AI recommendation unavailable')
            ->assertSet('processingRunId', null);
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

        Queue::assertPushed(GenerateAiProcurementRecommendationJob::class);

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
            $mock->shouldReceive('generateNarrativeSummary')->once()->andReturn('Reorder high-priority toner immediately.');
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
        $this->assertSame('draft', $run->status);
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
            'status' => 'draft',
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

        $this->assertNotNull($component->get('actionSummary'));

        $component
            ->set('categoryId', (string) $category->id)
            ->assertSet('actionSummary', null)
            ->assertSee('Generate a summary from the current at-risk table')
            ->assertDontSee('High priority');
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
