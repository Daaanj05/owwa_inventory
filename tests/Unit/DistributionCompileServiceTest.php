<?php

namespace Tests\Unit;

use App\Models\Department;
use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\DistributionCompileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DistributionCompileServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_and_creates_one_distribution_per_remaining_requisition_line(): void
    {
        [$service, $unitConsolidator, $employee, $office, $department, $requisition, $line, $item] = $this->distributionScenario();

        $distributionLines = $service->buildDistributionLines(collect([$requisition]), $office->id);

        $this->assertCount(1, $distributionLines);
        $this->assertSame(5, $distributionLines[0]['requested_quantity']);
        $this->assertSame(4, $distributionLines[0]['available_quantity']);
        $this->assertSame(4, $distributionLines[0]['quantity']);

        $distributionLines[0]['quantity'] = 3;
        $distributions = $service->createDistributions(
            $unitConsolidator,
            $office->id,
            $department->id,
            now()->toDateString(),
            $distributionLines,
        );

        $this->assertCount(1, $distributions);
        $this->assertDatabaseHas(Distribution::class, [
            'requisition_id' => $requisition->id,
            'requisition_item_id' => $line->id,
            'item_id' => $item->id,
            'distributed_to' => $employee->id,
            'quantity' => 3,
        ]);
        $this->assertSame(2, $service->remainingQuantityForLine($line));
    }

    public function test_it_caps_distribution_by_aggregate_office_balance(): void
    {
        [$service, $unitConsolidator, , $office, $department, $requisition] = $this->distributionScenario();
        $lines = $service->buildDistributionLines(collect([$requisition]), $office->id);
        $duplicate = $lines[0];
        $lines[0]['quantity'] = 3;
        $duplicate['quantity'] = 2;

        try {
            $service->validateDistributionLines(
                $unitConsolidator,
                $office->id,
                $department->id,
                [$lines[0], $duplicate],
            );
            $this->fail('Expected aggregate office balance validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('distribution_lines.1.quantity', $exception->errors());
        }
    }

    /**
     * @return array{DistributionCompileService, User, User, Office, Department, Requisition, RequisitionItem, Item}
     */
    private function distributionScenario(): array
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations',
            'code' => 'OPS',
        ]);
        $unitConsolidator = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $unitConsolidator->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $item = Item::factory()->create();
        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-3001',
            'purpose' => 'Office operations',
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 5,
        ]);
        $supplyRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $unitConsolidator->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);
        Issuance::query()->create([
            'requisition_id' => $supplyRequisition->id,
            'reference_code' => 'ISS-DIST-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 4,
            'issuance_date' => now(),
            'issued_by' => $unitConsolidator->id,
            'issued_to' => $unitConsolidator->id,
        ]);

        return [
            app(DistributionCompileService::class),
            $unitConsolidator,
            $employee,
            $office,
            $department,
            $requisition->load(['items.item', 'requestedBy']),
            $line,
            $item,
        ];
    }
}
