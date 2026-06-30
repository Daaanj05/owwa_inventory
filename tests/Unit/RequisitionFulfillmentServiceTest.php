<?php

namespace Tests\Unit;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\RequisitionFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class RequisitionFulfillmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RequisitionFulfillmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RequisitionFulfillmentService::class);
    }

    public function test_remaining_quantity_accounts_for_prior_issues(): void
    {
        $line = new RequisitionItem([
            'quantity' => 10,
            'quantity_issued' => 4,
        ]);

        $this->assertSame(6, $this->service->remainingQuantity($line));
    }

    public function test_issue_lines_sets_accepted_status_after_partial_issue(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-TEST-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 100,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        /** @var User $uc */
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR, 'office_id' => $office->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0010',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 10,
        ]);

        $created = $this->service->issueLines($requisition, $custodian, [
            [
                'requisition_item_id' => $line->id,
                'quantity_to_issue' => 4,
                'issue_remarks' => 'Partial first tranche',
            ],
        ], '2026-04-01');

        $this->assertSame(1, $created['created']);
        $this->assertSame(['Consumables' => 1], $created['categories']);
        $requisition->refresh();
        $line->refresh();

        $this->assertSame(Requisition::STATUS_ACCEPTED, $requisition->status);
        $this->assertSame(4, $line->quantity_issued);
        $this->assertTrue($requisition->hasRemainingToIssue());
        $this->assertSame(1, Issuance::query()->where('requisition_id', $requisition->id)->count());
    }

    public function test_issue_remainder_keeps_accepted_status_when_fully_issued(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-TEST-2',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 100,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        /** @var User $uc */
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR, 'office_id' => $office->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0011',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 10,
            'quantity_issued' => 4,
        ]);

        $created = $this->service->issueLines($requisition, $custodian, [
            [
                'requisition_item_id' => $line->id,
                'quantity_to_issue' => 6,
            ],
        ], '2026-04-02');

        $this->assertSame(1, $created['created']);
        $requisition->refresh();
        $line->refresh();

        $this->assertSame(Requisition::STATUS_ACCEPTED, $requisition->status);
        $this->assertSame(10, $line->quantity_issued);
        $this->assertFalse($requisition->hasRemainingToIssue());
    }

    public function test_issue_lines_rejects_quantity_exceeding_remaining(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-TEST-3',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 100,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        /** @var User $uc */
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR, 'office_id' => $office->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0013',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 5,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds remaining requested quantity');

        $this->service->issueLines($requisition, $custodian, [
            [
                'requisition_item_id' => $line->id,
                'quantity_to_issue' => 8,
            ],
        ], '2026-04-01');
    }

    public function test_reject_sets_status_and_remarks(): void
    {
        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $office = Office::factory()->create();
        /** @var User $uc */
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR, 'office_id' => $office->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0012',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $this->service->reject($requisition, $custodian, 'Insufficient stock for this period.');

        $requisition->refresh();

        $this->assertSame(Requisition::STATUS_REJECTED, $requisition->status);
        $this->assertSame('Insufficient stock for this period.', $requisition->remarks);
        $this->assertSame(0, Issuance::query()->count());
    }
}
