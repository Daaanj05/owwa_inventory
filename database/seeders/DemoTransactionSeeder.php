<?php

namespace Database\Seeders;

use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Models\Department;
use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockPositionRestockFlag;
use App\Models\Transfer;
use App\Models\User;
use App\Support\DemoInventoryWorkflow;
use App\Support\DemoStockLedgerCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoTransactionSeeder extends Seeder
{
    public function run(): void
    {
        $regional = Office::query()->firstWhere('code', DemoStockLedgerCatalog::REGIONAL_OFFICE);
        $satellite = Office::query()->firstWhere('code', DemoStockLedgerCatalog::SATELLITE_OFFICE);

        if (! $regional || ! $satellite) {
            return;
        }

        $sc = User::query()->where('email', 'custodian@owwa.gov.ph')->firstOrFail();
        $uc = User::query()->where('email', 'authorized@owwa.gov.ph')->firstOrFail();
        $joe1 = User::query()->where('email', 'maria@owwa.gov.ph')->firstOrFail();
        $joe2 = User::query()->where('email', 'juan@owwa.gov.ph')->firstOrFail();
        $joe3 = User::query()->where('email', 'anna@owwa.gov.ph')->firstOrFail();

        $admin = Department::query()->where('office_id', $regional->id)->where('code', 'ADM')->firstOrFail();
        $ops = Department::query()->where('office_id', $regional->id)->where('code', 'OPS')->firstOrFail();
        $finance = Department::query()->where('office_id', $regional->id)->where('code', 'FIN')->firstOrFail();

        $departments = [
            'ADM' => $admin,
            'OPS' => $ops,
            'FIN' => $finance,
        ];

        $itemMap = Item::query()
            ->whereIn('item_code', array_merge(
                DemoStockLedgerCatalog::allCoreItemCodes(),
                DemoStockLedgerCatalog::variantConsumableCodes(),
                DemoStockLedgerCatalog::variantPpeCodes(),
                ['CON-014'],
            ))
            ->get()
            ->keyBy('item_code');

        $workflow = app(DemoInventoryWorkflow::class);

        $this->seedStaticRequisitions(
            $regional,
            $ops,
            $finance,
            $joe1,
            $joe2,
            $joe3,
            $uc,
            $sc,
            $itemMap,
        );

        $compiledReq = Requisition::query()->where('reference_code', 'REQ-2026-0003')->firstOrFail();
        $workflow->issueAllLines($compiledReq->fresh(['items']), $sc, '2026-03-05');

        $demoReqSeq = 100;
        $issData = [];

        foreach (DemoStockLedgerCatalog::issuanceBatchRows() as $row) {
            $issData[] = [
                'item' => $row['item'],
                'qty' => $row['qty'],
                'date' => $row['date'],
                'dept' => $departments[$row['dept_code']],
            ];
        }

        $workflow->seedIssuanceBatchesFromGroups(
            $issData,
            $itemMap->all(),
            $regional,
            $joe1,
            $sc,
            $uc,
            $demoReqSeq,
        );

        $variantIssData = [];
        foreach (DemoStockLedgerCatalog::variantIssuanceBatchRows() as $row) {
            $variantIssData[] = [
                'item' => $row['item'],
                'qty' => $row['qty'],
                'date' => $row['date'],
                'dept' => $departments[$row['dept_code']],
            ];
        }

        $workflow->seedIssuanceBatchesFromGroups(
            $variantIssData,
            $itemMap->all(),
            $regional,
            $joe1,
            $sc,
            $uc,
            $demoReqSeq,
            'REQ-VAR-',
        );

        $welfare = Department::query()
            ->where('office_id', $satellite->id)
            ->where('code', 'WSU')
            ->first();

        if ($welfare) {
            $satelliteIssData = [];
            foreach (DemoStockLedgerCatalog::satelliteVariantIssuanceBatchRows() as $row) {
                $satelliteIssData[] = [
                    'item' => $row['item'],
                    'qty' => $row['qty'],
                    'date' => $row['date'],
                    'dept' => $welfare,
                ];
            }

            $ucSatellite = User::query()->where('email', 'consolidator2@owwa.gov.ph')->first() ?? $uc;

            $workflow->seedIssuanceBatchesFromGroups(
                $satelliteIssData,
                $itemMap->all(),
                $satellite,
                $ucSatellite,
                $sc,
                $uc,
                $demoReqSeq,
                'REQ-SAT-',
            );
        }

        $trSeq = 1;

        foreach (DemoStockLedgerCatalog::transferRows() as $row) {
            $item = $itemMap[$row['item']] ?? null;

            if (! $item) {
                continue;
            }

            $ref = 'PTR-2026-'.str_pad((string) $trSeq++, 4, '0', STR_PAD_LEFT);
            Transfer::updateOrCreate(
                ['reference_code' => $ref],
                [
                    'item_id' => $item->id,
                    'from_office_id' => $regional->id,
                    'to_office_id' => $satellite->id,
                    'quantity' => $row['qty'],
                    'transfer_date' => Carbon::parse($row['date']),
                    'condition' => 'Serviceable',
                    'recorded_by' => $sc->id,
                ],
            );
        }

        $distData = [
            ['to' => $joe1, 'item' => 'CON-001', 'qty' => 5, 'date' => '2026-03-07', 'remarks' => 'Monthly supply'],
            ['to' => $joe1, 'item' => 'CON-002', 'qty' => 10, 'date' => '2026-03-07', 'remarks' => 'Monthly supply'],
            ['to' => $joe1, 'item' => 'CON-008', 'qty' => 3, 'date' => '2026-03-07', 'remarks' => 'Monthly supply'],
            ['to' => $joe2, 'item' => 'CON-001', 'qty' => 3, 'date' => '2026-03-07', 'remarks' => 'Fieldwork allocation'],
            ['to' => $joe2, 'item' => 'CON-006', 'qty' => 5, 'date' => '2026-03-07', 'remarks' => 'Fieldwork allocation'],
            ['to' => $joe3, 'item' => 'CON-001', 'qty' => 4, 'date' => '2026-03-10', 'remarks' => 'Finance quarterly supply'],
            ['to' => $joe3, 'item' => 'CON-002', 'qty' => 8, 'date' => '2026-03-10', 'remarks' => 'Finance quarterly supply'],
            ['to' => $joe1, 'item' => 'CON-001', 'qty' => 3, 'date' => '2026-04-05', 'remarks' => 'April replenishment'],
            ['to' => $joe1, 'item' => 'CON-006', 'qty' => 4, 'date' => '2026-04-05', 'remarks' => 'April replenishment'],
        ];

        foreach ($distData as $d) {
            Distribution::updateOrCreate(
                [
                    'requisition_id' => $compiledReq->id,
                    'item_id' => $itemMap[$d['item']]->id,
                    'distributed_to' => $d['to']->id,
                    'distribution_date' => Carbon::parse($d['date'])->toDateString(),
                    'quantity' => $d['qty'],
                ],
                [
                    'office_id' => $regional->id,
                    'department_id' => $ops->id,
                    'distributed_by' => $uc->id,
                    'remarks' => $d['remarks'],
                ],
            );
        }

        $this->seedZeroStockRestockLifecycle(
            $regional,
            $admin,
            $joe1,
            $uc,
            $sc,
            $itemMap,
            $workflow,
        );

        $issSeq = Issuance::query()->count() + 1;
        \App\Models\ReferenceSeries::where('type', 'issuance')->update(['next_sequence' => $issSeq, 'last_generated_at' => now()]);
        \App\Models\ReferenceSeries::where('type', 'transfer')->update(['next_sequence' => $trSeq, 'last_generated_at' => now()]);
        \App\Models\ReferenceSeries::where('type', 'requisition')->update(['next_sequence' => max(200, $demoReqSeq + 5), 'last_generated_at' => now()]);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Item>  $itemMap
     */
    protected function seedStaticRequisitions(
        Office $regional,
        Department $ops,
        Department $finance,
        User $joe1,
        User $joe2,
        User $joe3,
        User $uc,
        User $sc,
        $itemMap,
    ): void {
        $req1 = Requisition::updateOrCreate(
            ['reference_code' => 'REQ-2026-0001'],
            [
                'office_id' => $regional->id,
                'department_id' => $ops->id,
                'requested_by' => $joe1->id,
                'status' => Requisition::STATUS_ACCEPTED,
                'remarks' => 'Monthly office supply request',
                'approved_by' => $uc->id,
                'approved_at' => Carbon::parse('2026-03-02'),
            ],
        );
        RequisitionItem::updateOrCreate(
            ['requisition_id' => $req1->id, 'item_id' => $itemMap['CON-001']->id],
            ['quantity' => 5],
        );
        RequisitionItem::updateOrCreate(
            ['requisition_id' => $req1->id, 'item_id' => $itemMap['CON-002']->id],
            ['quantity' => 10],
        );
        RequisitionItem::updateOrCreate(
            ['requisition_id' => $req1->id, 'item_id' => $itemMap['CON-008']->id],
            ['quantity' => 3],
        );

        $req2 = Requisition::updateOrCreate(
            ['reference_code' => 'REQ-2026-0002'],
            [
                'office_id' => $regional->id,
                'department_id' => $ops->id,
                'requested_by' => $joe2->id,
                'status' => Requisition::STATUS_ACCEPTED,
                'remarks' => 'Supplies for fieldwork',
                'approved_by' => $uc->id,
                'approved_at' => Carbon::parse('2026-03-02'),
            ],
        );
        RequisitionItem::updateOrCreate(
            ['requisition_id' => $req2->id, 'item_id' => $itemMap['CON-001']->id],
            ['quantity' => 3],
        );
        RequisitionItem::updateOrCreate(
            ['requisition_id' => $req2->id, 'item_id' => $itemMap['CON-006']->id],
            ['quantity' => 5],
        );

        $compiledReq = Requisition::updateOrCreate(
            ['reference_code' => 'REQ-2026-0003'],
            [
                'office_id' => $regional->id,
                'department_id' => $ops->id,
                'requested_by' => $uc->id,
                'status' => Requisition::STATUS_ACCEPTED,
                'remarks' => 'Consolidated request from Operations Division',
                'approved_by' => $sc->id,
                'approved_at' => Carbon::parse('2026-03-05'),
            ],
        );
        RequisitionItem::updateOrCreate(
            ['requisition_id' => $compiledReq->id, 'item_id' => $itemMap['CON-001']->id],
            ['quantity' => 8],
        );
        RequisitionItem::updateOrCreate(
            ['requisition_id' => $compiledReq->id, 'item_id' => $itemMap['CON-002']->id],
            ['quantity' => 10],
        );
        RequisitionItem::updateOrCreate(
            ['requisition_id' => $compiledReq->id, 'item_id' => $itemMap['CON-006']->id],
            ['quantity' => 5],
        );
        RequisitionItem::updateOrCreate(
            ['requisition_id' => $compiledReq->id, 'item_id' => $itemMap['CON-008']->id],
            ['quantity' => 3],
        );

        $req1->update(['compiled_into_requisition_id' => $compiledReq->id]);
        $req2->update(['compiled_into_requisition_id' => $compiledReq->id]);

        $req4 = Requisition::updateOrCreate(
            ['reference_code' => 'REQ-2026-0004'],
            [
                'office_id' => $regional->id,
                'department_id' => $ops->id,
                'requested_by' => $joe1->id,
                'status' => Requisition::STATUS_PENDING,
                'remarks' => 'Need more ink cartridges and bond paper',
            ],
        );

        RequisitionItem::updateOrCreate(
            ['requisition_id' => $req4->id, 'item_id' => $itemMap['CON-003']->id],
            ['quantity' => 4],
        );
        RequisitionItem::updateOrCreate(
            ['requisition_id' => $req4->id, 'item_id' => $itemMap['CON-001']->id],
            ['quantity' => 5],
        );

        $req5 = Requisition::updateOrCreate(
            ['reference_code' => 'REQ-2026-0005'],
            [
                'office_id' => $regional->id,
                'department_id' => $finance->id,
                'requested_by' => $joe3->id,
                'status' => Requisition::STATUS_REJECTED,
                'remarks' => 'Request for whiteboard — already allocated this quarter',
                'approved_by' => $uc->id,
                'approved_at' => Carbon::parse('2026-03-08'),
            ],
        );
        RequisitionItem::updateOrCreate(
            ['requisition_id' => $req5->id, 'item_id' => $itemMap['SEM-005']->id],
            ['quantity' => 2],
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Item>  $itemMap
     */
    protected function seedZeroStockRestockLifecycle(
        Office $regional,
        Department $admin,
        User $employee,
        User $unitConsolidator,
        User $supplyCustodian,
        $itemMap,
        DemoInventoryWorkflow $workflow,
    ): void {
        $item = $itemMap['CON-014'] ?? null;

        if (! $item) {
            return;
        }

        $unitCost = 18.75;
        $depletedQuantity = 40;

        Acquisition::query()->updateOrCreate(
            ['reference_code' => 'ACQ-DEMO-CON-014-ZERO'],
            [
                'item_id' => $item->id,
                'office_id' => $regional->id,
                'quantity' => $depletedQuantity,
                'unit_cost' => $unitCost,
                'acquisition_date' => Carbon::parse('2024-01-15'),
                'source' => 'Demo procurement for restock lifecycle',
                'remarks' => 'Stock fully issued before automatic inactivity',
                'recorded_by' => $supplyCustodian->id,
            ],
        );

        $depletionRequisition = $workflow->seedRequisition(
            referenceCode: 'REQ-DEMO-CON-014-DEPLETION',
            office: $regional,
            department: $admin,
            requestedBy: $employee,
            lines: [['item' => $item, 'quantity' => $depletedQuantity]],
            approvedBy: $supplyCustodian,
            approvedAt: Carbon::parse('2024-06-30'),
            remarks: 'Demo issuance that depleted CON-014',
        );
        $workflow->issueAllLines($depletionRequisition, $supplyCustodian, '2024-07-01');

        StockPositionRestockFlag::markAutomaticallyInactive(
            (int) $item->id,
            (int) $regional->id,
            $unitCost,
            Carbon::parse('2024-07-01'),
        );

        $restockRequisition = Requisition::query()->updateOrCreate(
            ['reference_code' => 'REQ-2026-0006'],
            [
                'office_id' => $regional->id,
                'department_id' => $admin->id,
                'requested_by' => $unitConsolidator->id,
                'status' => Requisition::STATUS_PENDING,
                'purpose' => 'Restock zero-stock Folder Short (demo)',
                'remarks' => 'Compiled UC request with zero regional stock',
                'approved_by' => null,
                'approved_at' => null,
            ],
        );

        $restockLine = RequisitionItem::query()->updateOrCreate(
            [
                'requisition_id' => $restockRequisition->id,
                'item_id' => $item->id,
            ],
            [
                'quantity' => 30,
                'stock_at_request' => 0,
                'stock_available' => 0,
                'quantity_issued' => 0,
                'issue_remarks' => null,
            ],
        );

        $paperwork = AcquisitionPaperwork::query()->updateOrCreate(
            ['reference_code' => 'DEMO-PR-CON-ZERO-STOCK'],
            [
                'office_id' => $regional->id,
                'requesting_office_id' => $regional->id,
                'department_id' => $admin->id,
                'item_category_id' => $item->item_category_id,
                'recorded_by' => $supplyCustodian->id,
                'phase' => AcquisitionPaperwork::PHASE_PR,
                'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
                'po_status' => AcquisitionPaperwork::STATUS_DRAFT,
                'iar_status' => AcquisitionPaperwork::STATUS_DRAFT,
                'pr_number' => null,
                'po_number' => null,
                'iar_number' => null,
                'pr_date' => Carbon::parse('2026-07-10'),
                'po_date' => null,
                'iar_date' => null,
                'purpose' => 'Restock zero-stock Folder Short (demo)',
                'supplier' => null,
                'pr_submitted_at' => null,
                'po_submitted_at' => null,
                'iar_submitted_at' => null,
                'pr_completed_at' => null,
                'po_completed_at' => null,
                'iar_completed_at' => null,
                'received_at' => null,
            ],
        );

        $paperworkLine = AcquisitionPaperworkLine::query()->updateOrCreate(
            [
                'acquisition_paperwork_id' => $paperwork->id,
                'item_id' => $item->id,
            ],
            [
                'description' => $item->name,
                'unit' => $item->unit,
                'quantity' => 20,
                'unit_cost' => null,
                'amount' => null,
                'line_remarks' => 'Cost to be supplied during procurement',
            ],
        );

        $paperwork->requisitions()->syncWithoutDetaching([$restockRequisition->id]);
        $paperworkLine->requisitionItems()->sync([
            $restockLine->id => ['quantity' => 20],
        ]);
    }
}
