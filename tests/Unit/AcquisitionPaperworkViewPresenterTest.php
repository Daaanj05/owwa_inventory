<?php

namespace Tests\Unit;

use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Support\AcquisitionPaperworkViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcquisitionPaperworkViewPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_steps_start_with_pr_active(): void
    {
        $paperwork = $this->createPaperworkWithLine([
            'purpose' => 'Office supplies',
            'pr_date' => now(),
        ]);

        $steps = AcquisitionPaperworkViewPresenter::workflowSteps($paperwork);

        $this->assertCount(4, $steps);
        $this->assertSame('PR', $steps[0]['shortLabel']);
        $this->assertSame('active', $steps[0]['state']);
        $this->assertSame('pending', $steps[1]['state']);
        $this->assertSame('Received', $steps[3]['shortLabel']);
        $this->assertSame('Locked', $steps[3]['statusLabel']);
        $this->assertNull($steps[0]['url']);
    }

    public function test_workflow_steps_for_new_form_defaults_to_pr_active(): void
    {
        $steps = AcquisitionPaperworkViewPresenter::workflowStepsForForm(null);

        $this->assertCount(4, $steps);
        $this->assertSame('PR', $steps[0]['shortLabel']);
        $this->assertSame('active', $steps[0]['state']);
        $this->assertSame('pending', $steps[1]['state']);
        $this->assertSame('Draft', $steps[0]['statusLabel']);
    }

    public function test_po_and_iar_sections_hidden_on_create_operation(): void
    {
        $operation = 'create';
        $record = null;

        $poVisible = $operation !== 'create' && ($record?->isPrApproved() ?? false);
        $iarVisible = $operation !== 'create' && ($record?->isPoApproved() ?? false);

        $this->assertFalse($poVisible);
        $this->assertFalse($iarVisible);
    }

    public function test_workflow_steps_after_pr_complete_unlocks_po(): void
    {
        $paperwork = $this->createPaperworkWithLine([
            'purpose' => 'Office supplies',
            'pr_date' => now(),
        ]);

        app(AcquisitionPaperworkCompletionService::class)->completePr($paperwork->fresh());

        $paperwork = $paperwork->fresh();
        $steps = AcquisitionPaperworkViewPresenter::workflowSteps($paperwork);

        $this->assertSame('done', $steps[0]['state']);
        $this->assertNotNull($steps[0]['url']);
        $this->assertSame('pending', $steps[1]['state']);
        $this->assertSame(25, AcquisitionPaperworkViewPresenter::progressPercent($paperwork));
    }

    public function test_progress_percent_reaches_one_hundred_when_iar_complete(): void
    {
        $paperwork = $this->completeThroughIar($this->createPaperworkWithLine([
            'purpose' => 'Test acquisition',
            'pr_date' => now(),
            'supplier' => 'ABC Trading',
            'po_date' => now(),
            'iar_date' => now(),
            'inspection_officer_name' => 'Inspector',
            'custodian_name' => 'Custodian',
        ]));

        $this->assertSame(75, AcquisitionPaperworkViewPresenter::progressPercent($paperwork));
        $this->assertSame('done', AcquisitionPaperworkViewPresenter::workflowSteps($paperwork)[2]['state']);
        $this->assertNotNull($paperwork->iar_number);
    }

    public function test_progress_percent_reaches_one_hundred_when_received(): void
    {
        $paperwork = $this->completeThroughIar($this->createPaperworkWithLine([
            'purpose' => 'Test acquisition',
            'pr_date' => now(),
            'supplier' => 'ABC Trading',
            'po_date' => now(),
            'iar_date' => now(),
            'inspection_officer_name' => 'Inspector',
            'custodian_name' => 'Custodian',
        ]));

        $paperwork->lines()->update(['unit_cost' => 50]);
        app(AcquisitionPaperworkCompletionService::class)->recordCustodyReceipts($paperwork->fresh());

        $paperwork = $paperwork->fresh();
        $this->assertSame(100, AcquisitionPaperworkViewPresenter::progressPercent($paperwork));
        $this->assertTrue($paperwork->isReceived());
        $this->assertSame('done', AcquisitionPaperworkViewPresenter::workflowSteps($paperwork)[3]['state']);
        $this->assertSame('Received', AcquisitionPaperworkViewPresenter::workflowSteps($paperwork)[3]['statusLabel']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createPaperworkWithLine(array $overrides = []): AcquisitionPaperwork
    {
        $office = Office::factory()->create(['fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create();
        $paperwork = AcquisitionPaperwork::query()->create(array_merge([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'phase' => AcquisitionPaperwork::PHASE_PR,
        ], $overrides));

        AcquisitionPaperworkLine::query()->create([
            'acquisition_paperwork_id' => $paperwork->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => $item->unit,
            'quantity' => 5,
            'unit_cost' => 25.50,
            'amount' => 127.50,
        ]);

        return $paperwork->fresh(['lines.item', 'office', 'itemCategory']);
    }

    protected function completeThroughIar(AcquisitionPaperwork $paperwork): AcquisitionPaperwork
    {
        $service = app(AcquisitionPaperworkCompletionService::class);

        $paperwork->lines()->update(['unit_cost' => 50]);

        $service->completePr($paperwork->fresh());
        $service->completePo($paperwork->fresh());
        $service->completeIar($paperwork->fresh());

        return $paperwork->fresh();
    }
}
