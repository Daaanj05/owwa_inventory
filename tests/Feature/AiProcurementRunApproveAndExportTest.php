<?php

namespace Tests\Feature;

use App\Filament\Resources\AiProcurementRunResource\Pages\ListAiProcurementRuns;
use App\Models\AiProcurementItem;
use App\Models\AiProcurementRun;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\TestCase;

class AiProcurementRunApproveAndExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_approved_updates_status_notifies_and_hides_action(): void
    {
        $this->actingAsCustodian();
        $run = $this->createRun('pending', now());

        Livewire::test(ListAiProcurementRuns::class)
            ->assertActionVisible([
                TestAction::make('view')->table($run),
                TestAction::make('approveRun'),
            ])
            ->callAction([
                TestAction::make('view')->table($run),
                TestAction::make('approveRun'),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Run marked approved');

        $this->assertSame('approved', $run->refresh()->status);

        Livewire::test(ListAiProcurementRuns::class)
            ->assertActionHidden([
                TestAction::make('view')->table($run),
                TestAction::make('approveRun'),
            ]);
    }

    public function test_run_pdf_export_downloads_for_custodian(): void
    {
        $custodian = $this->actingAsCustodian();
        $run = $this->createRun('pending', now());
        AiProcurementItem::query()->create([
            'run_id' => $run->id,
            'section' => 'consumables',
            'priority' => 'high',
            'item_name' => 'Bond Paper A4',
            'office_name' => 'Regional Office',
            'current_stock' => 4,
            'avg_monthly_usage' => 10,
            'months_cover' => 0.4,
            'suggested_qty_min' => 20,
            'suggested_qty_max' => 30,
            'reason' => 'Low cover',
            'action_status' => 'pending',
        ]);

        $response = $this->actingAs($custodian)->get(route('ai-procurement-runs.export-pdf', $run));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_pdf_exports_are_forbidden_for_other_roles(): void
    {
        $run = $this->createRun('pending', now());
        $consolidator = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);

        $this->actingAs($consolidator)
            ->get(route('ai-procurement-runs.export-pdf', $run))
            ->assertForbidden();

        $this->actingAs($consolidator)
            ->get(route('ai-procurement-runs.export-history-pdf'))
            ->assertForbidden();
    }

    public function test_history_pdf_filters_by_date_range_status_and_archive(): void
    {
        $custodian = $this->actingAsCustodian();
        $this->createRun('approved', now()->setDate(2026, 3, 10));
        $this->createRun('pending', now()->setDate(2026, 3, 12));
        $this->createRun('approved', now()->setDate(2025, 6, 1));
        $archived = $this->createRun('approved', now()->setDate(2026, 3, 15));
        $archived->archive();

        $captured = [];
        View::creator('reports.ai-procurement-history-pdf', function ($view) use (&$captured): void {
            $captured = $view->getData();
        });

        $response = $this->actingAs($custodian)->get(route('ai-procurement-runs.export-history-pdf', [
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'status' => 'approved',
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertCount(1, $captured['rows']);
        $this->assertSame('Approved', $captured['rows'][0]['status']);
        $this->assertStringContainsString('Active runs', $captured['filterSummary']);

        $this->actingAs($custodian)->get(route('ai-procurement-runs.export-history-pdf', [
            'archived' => 1,
        ]))->assertOk();
        $this->assertCount(1, $captured['rows']);
        $this->assertSame('Run #'.$archived->id, $captured['rows'][0]['reference']);
    }

    public function test_history_pdf_rejects_inverted_date_range(): void
    {
        $custodian = $this->actingAsCustodian();

        $this->actingAs($custodian)
            ->getJson(route('ai-procurement-runs.export-history-pdf', [
                'date_from' => '2026-05-01',
                'date_to' => '2026-01-01',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    protected function actingAsCustodian(): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        return $custodian;
    }

    protected function createRun(string $status, \DateTimeInterface $ranAt): AiProcurementRun
    {
        return AiProcurementRun::query()->create([
            'ran_at' => $ranAt,
            'summary' => 'Run summary',
            'status' => $status,
        ]);
    }
}
