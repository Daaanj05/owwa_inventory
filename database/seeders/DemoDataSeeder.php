<?php

namespace Database\Seeders;

use App\Events\RequisitionChanged;
use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Disposal;
use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        Event::fake([RequisitionChanged::class]);

        // ─── Offices (idempotent: safe to re-run) ───────────────
        $regional = Office::firstOrCreate(
            ['code' => 'OWWA-IVA'],
            [
                'name' => 'OWWA Regional Office IV-A',
                'fund_cluster' => '01',
                'is_satellite' => false,
                'address' => 'CALABARZON',
            ],
        );

        $satellite = Office::firstOrCreate(
            ['code' => 'OWWA-LAG'],
            [
                'name' => 'OWWA Satellite Office — Laguna',
                'fund_cluster' => '01',
                'is_satellite' => true,
                'address' => 'Sta. Cruz, Laguna',
            ],
        );

        // ─── Departments (unique per fiscal year + office + name) ─
        $admin = Department::firstOrCreate(
            ['office_id' => $regional->id, 'name' => 'Administrative Division'],
            ['code' => 'ADM'],
        );
        $ops = Department::firstOrCreate(
            ['office_id' => $regional->id, 'name' => 'Operations Division'],
            ['code' => 'OPS'],
        );
        $finance = Department::firstOrCreate(
            ['office_id' => $regional->id, 'name' => 'Finance Division'],
            ['code' => 'FIN'],
        );
        $welfare = Department::firstOrCreate(
            ['office_id' => $satellite->id, 'name' => 'Welfare Services Unit'],
            ['code' => 'WSU'],
        );

        // ─── Users ──────────────────────────────────────────────
        $sc = User::where('email', 'custodian@owwa.gov.ph')->first();
        $sc?->update(['office_id' => $regional->id, 'department_id' => $admin->id]);

        $uc = User::where('email', 'authorized@owwa.gov.ph')->first();
        $uc?->update(['office_id' => $regional->id, 'department_id' => $ops->id]);

        $sysAdmin = User::where('email', 'admin@owwa.gov.ph')->first();
        $sysAdmin?->update(['office_id' => $regional->id]);

        $joe1 = User::where('email', 'test@example.com')->first();
        if ($joe1) {
            $joe1->update([
                'name' => 'Maria Santos',
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $regional->id,
                'department_id' => $ops->id,
            ]);
        }

        $joe2 = User::updateOrCreate(
            ['email' => 'juan@owwa.gov.ph'],
            [
                'name' => 'Juan Dela Cruz',
                'password' => Hash::make('password'),
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $regional->id,
                'department_id' => $ops->id,
            ]
        );

        $joe3 = User::updateOrCreate(
            ['email' => 'anna@owwa.gov.ph'],
            [
                'name' => 'Anna Reyes',
                'password' => Hash::make('password'),
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $regional->id,
                'department_id' => $finance->id,
            ]
        );

        $uc2 = User::updateOrCreate(
            ['email' => 'consolidator2@owwa.gov.ph'],
            [
                'name' => 'Roberto Cruz',
                'password' => Hash::make('password'),
                'role' => User::ROLE_UNIT_CONSOLIDATOR,
                'office_id' => $satellite->id,
                'department_id' => $welfare->id,
            ]
        );

        // ─── Categories (already seeded) ─────────────────────────
        $consumables = ItemCategory::firstOrCreate(
            ['name' => 'Consumables'],
            ['description' => 'Office consumables and supplies'],
        );
        $semiExpendable = ItemCategory::firstOrCreate(
            ['name' => 'Semi-Expendable'],
            ['description' => 'Semi-expendable properties'],
        );
        $ppe = ItemCategory::firstOrCreate(
            ['name' => 'PPE'],
            ['description' => 'Property, plant and equipment'],
        );

        // ─── Items (unique per fiscal year + item_code) ─────────
        // Consumables
        $consumableItems = [
            ['name' => 'Bond Paper A4 (Ream)', 'unit' => 'ream', 'item_code' => 'CON-001', 'reorder_level' => 20],
            ['name' => 'Ballpoint Pen (Blue)', 'unit' => 'piece', 'item_code' => 'CON-002', 'reorder_level' => 50],
            ['name' => 'Ink Cartridge (Black)', 'unit' => 'piece', 'item_code' => 'CON-003', 'reorder_level' => 10],
            ['name' => 'Folder (Long)', 'unit' => 'piece', 'item_code' => 'CON-004', 'reorder_level' => 30],
            ['name' => 'Staple Wire No. 35', 'unit' => 'box', 'item_code' => 'CON-005', 'reorder_level' => 15],
            ['name' => 'Alcohol 70% (500ml)', 'unit' => 'bottle', 'item_code' => 'CON-006', 'reorder_level' => 25],
            ['name' => 'Tissue Paper (Roll)', 'unit' => 'roll', 'item_code' => 'CON-007', 'reorder_level' => 40],
            ['name' => 'Correction Tape', 'unit' => 'piece', 'item_code' => 'CON-008', 'reorder_level' => 20],
        ];

        foreach ($consumableItems as $ci) {
            Item::firstOrCreate(
                ['item_code' => $ci['item_code']],
                [
                    'item_category_id' => $consumables->id,
                    'name' => $ci['name'],
                    'unit' => $ci['unit'],
                    'value_type' => 'low',
                    'reorder_level' => $ci['reorder_level'],
                ],
            );
        }

        // Semi-Expendable
        $semiItems = [
            ['name' => 'Heavy-Duty Stapler', 'unit' => 'piece', 'item_code' => 'SEM-001', 'reorder_level' => 5],
            ['name' => 'Paper Cutter', 'unit' => 'piece', 'item_code' => 'SEM-002', 'reorder_level' => 3],
            ['name' => 'Desk Organizer', 'unit' => 'piece', 'item_code' => 'SEM-003', 'reorder_level' => 5],
            ['name' => 'Wall Clock', 'unit' => 'piece', 'item_code' => 'SEM-004', 'reorder_level' => 2],
            ['name' => 'Whiteboard (4x3 ft)', 'unit' => 'piece', 'item_code' => 'SEM-005', 'reorder_level' => 2],
        ];

        foreach ($semiItems as $si) {
            Item::firstOrCreate(
                ['item_code' => $si['item_code']],
                [
                    'item_category_id' => $semiExpendable->id,
                    'name' => $si['name'],
                    'unit' => $si['unit'],
                    'value_type' => 'low',
                    'reorder_level' => $si['reorder_level'],
                ],
            );
        }

        // PPE
        $ppeItems = [
            ['name' => 'Laptop (ThinkPad L14)', 'unit' => 'unit', 'item_code' => 'PPE-001', 'reorder_level' => 2, 'value_type' => 'high'],
            ['name' => 'Office Desk', 'unit' => 'unit', 'item_code' => 'PPE-002', 'reorder_level' => 2, 'value_type' => 'high'],
            ['name' => 'Printer (Laser)', 'unit' => 'unit', 'item_code' => 'PPE-003', 'reorder_level' => 1, 'value_type' => 'high'],
            ['name' => 'Air Conditioning Unit', 'unit' => 'unit', 'item_code' => 'PPE-004', 'reorder_level' => 1, 'value_type' => 'high'],
        ];

        foreach ($ppeItems as $pi) {
            Item::firstOrCreate(
                ['item_code' => $pi['item_code']],
                [
                    'item_category_id' => $ppe->id,
                    'name' => $pi['name'],
                    'unit' => $pi['unit'],
                    'value_type' => $pi['value_type'],
                    'reorder_level' => $pi['reorder_level'],
                ],
            );
        }

        $demoItemCodes = array_merge(
            array_column($consumableItems, 'item_code'),
            array_column($semiItems, 'item_code'),
            array_column($ppeItems, 'item_code'),
        );
        $itemMap = Item::query()
            ->whereIn('item_code', $demoItemCodes)
            ->get()
            ->keyBy('item_code');

        // ─── Acquisitions ───────────────────────────────────────
        // Regional office receives bulk supplies
        $acqData = [
            ['item' => 'CON-001', 'qty' => 100, 'cost' => 185.00, 'date' => '2026-01-15', 'source' => 'PS-DBM'],
            ['item' => 'CON-002', 'qty' => 200, 'cost' => 8.50, 'date' => '2026-01-15', 'source' => 'PS-DBM'],
            ['item' => 'CON-003', 'qty' => 40, 'cost' => 850.00, 'date' => '2026-01-20', 'source' => 'Supplier — InkWell Trading'],
            ['item' => 'CON-004', 'qty' => 150, 'cost' => 12.00, 'date' => '2026-01-15', 'source' => 'PS-DBM'],
            ['item' => 'CON-005', 'qty' => 60, 'cost' => 45.00, 'date' => '2026-01-15', 'source' => 'PS-DBM'],
            ['item' => 'CON-006', 'qty' => 80, 'cost' => 95.00, 'date' => '2026-02-03', 'source' => 'PS-DBM'],
            ['item' => 'CON-007', 'qty' => 120, 'cost' => 35.00, 'date' => '2026-02-03', 'source' => 'PS-DBM'],
            ['item' => 'CON-008', 'qty' => 60, 'cost' => 28.00, 'date' => '2026-02-03', 'source' => 'PS-DBM'],
            ['item' => 'SEM-001', 'qty' => 15, 'cost' => 380.00, 'date' => '2026-01-22', 'source' => 'National Book Store'],
            ['item' => 'SEM-002', 'qty' => 8, 'cost' => 450.00, 'date' => '2026-01-22', 'source' => 'National Book Store'],
            ['item' => 'SEM-003', 'qty' => 20, 'cost' => 250.00, 'date' => '2026-01-22', 'source' => 'National Book Store'],
            ['item' => 'SEM-004', 'qty' => 10, 'cost' => 350.00, 'date' => '2026-02-10', 'source' => 'Supplier — Clockworks Ph'],
            ['item' => 'SEM-005', 'qty' => 6, 'cost' => 1200.00, 'date' => '2026-02-10', 'source' => 'Supplier — Office Depot'],
            ['item' => 'PPE-001', 'qty' => 10, 'cost' => 55000.00, 'date' => '2026-01-10', 'source' => 'Procurement — Lenovo Philippines'],
            ['item' => 'PPE-002', 'qty' => 12, 'cost' => 55000.00, 'date' => '2026-01-12', 'source' => 'Procurement — Mandaue Foam'],
            ['item' => 'PPE-003', 'qty' => 5, 'cost' => 55000.00, 'date' => '2026-01-12', 'source' => 'Procurement — Brother Philippines'],
            ['item' => 'PPE-004', 'qty' => 4, 'cost' => 55000.00, 'date' => '2026-01-18', 'source' => 'Procurement — Carrier Aircon'],
        ];

        // Additional Q2 acquisitions to simulate replenishment
        $acqDataQ2 = [
            ['item' => 'CON-001', 'qty' => 50, 'cost' => 190.00, 'date' => '2026-04-01', 'source' => 'PS-DBM (Q2)'],
            ['item' => 'CON-002', 'qty' => 100, 'cost' => 8.75, 'date' => '2026-04-01', 'source' => 'PS-DBM (Q2)'],
            ['item' => 'CON-006', 'qty' => 40, 'cost' => 98.00, 'date' => '2026-04-01', 'source' => 'PS-DBM (Q2)'],
        ];

        $acqSeq = 1;
        foreach (array_merge($acqData, $acqDataQ2) as $a) {
            $ref = 'ACQ-2026-'.str_pad($acqSeq++, 4, '0', STR_PAD_LEFT);
            Acquisition::updateOrCreate(
                ['reference_code' => $ref],
                [
                    'item_id' => $itemMap[$a['item']]->id,
                    'office_id' => $regional->id,
                    'quantity' => $a['qty'],
                    'unit_cost' => $a['cost'],
                    'acquisition_date' => Carbon::parse($a['date']),
                    'source' => $a['source'],
                    'recorded_by' => $sc->id,
                ],
            );
        }

        // Satellite office also gets some acquisitions
        $satAcq = [
            ['item' => 'CON-001', 'qty' => 30, 'cost' => 185.00, 'date' => '2026-01-20', 'source' => 'PS-DBM'],
            ['item' => 'CON-002', 'qty' => 50, 'cost' => 8.50, 'date' => '2026-01-20', 'source' => 'PS-DBM'],
            ['item' => 'CON-006', 'qty' => 20, 'cost' => 95.00, 'date' => '2026-02-05', 'source' => 'PS-DBM'],
            ['item' => 'SEM-001', 'qty' => 5, 'cost' => 380.00, 'date' => '2026-02-05', 'source' => 'National Book Store'],
            ['item' => 'PPE-001', 'qty' => 3, 'cost' => 55000.00, 'date' => '2026-01-15', 'source' => 'Procurement — Lenovo Philippines'],
        ];

        foreach ($satAcq as $a) {
            $ref = 'ACQ-2026-'.str_pad($acqSeq++, 4, '0', STR_PAD_LEFT);
            Acquisition::updateOrCreate(
                ['reference_code' => $ref],
                [
                    'item_id' => $itemMap[$a['item']]->id,
                    'office_id' => $satellite->id,
                    'quantity' => $a['qty'],
                    'unit_cost' => $a['cost'],
                    'acquisition_date' => Carbon::parse($a['date']),
                    'source' => $a['source'],
                    'recorded_by' => $sc->id,
                ],
            );
        }

        // ─── Issuances ──────────────────────────────────────────
        // Monthly issuances from regional office (reduces stock)
        $issData = [
            // January
            ['item' => 'CON-001', 'qty' => 15, 'date' => '2026-01-20', 'dept' => $admin],
            ['item' => 'CON-002', 'qty' => 30, 'date' => '2026-01-20', 'dept' => $admin],
            ['item' => 'CON-004', 'qty' => 20, 'date' => '2026-01-20', 'dept' => $admin],
            ['item' => 'CON-001', 'qty' => 10, 'date' => '2026-01-22', 'dept' => $ops],
            ['item' => 'CON-002', 'qty' => 25, 'date' => '2026-01-22', 'dept' => $ops],
            ['item' => 'CON-003', 'qty' => 5, 'date' => '2026-01-25', 'dept' => $ops],
            // February
            ['item' => 'CON-001', 'qty' => 12, 'date' => '2026-02-10', 'dept' => $finance],
            ['item' => 'CON-002', 'qty' => 20, 'date' => '2026-02-10', 'dept' => $finance],
            ['item' => 'CON-006', 'qty' => 10, 'date' => '2026-02-10', 'dept' => $admin],
            ['item' => 'CON-007', 'qty' => 15, 'date' => '2026-02-12', 'dept' => $admin],
            ['item' => 'CON-005', 'qty' => 8, 'date' => '2026-02-15', 'dept' => $ops],
            ['item' => 'CON-008', 'qty' => 10, 'date' => '2026-02-15', 'dept' => $ops],
            // March
            ['item' => 'CON-001', 'qty' => 18, 'date' => '2026-03-05', 'dept' => $admin],
            ['item' => 'CON-002', 'qty' => 35, 'date' => '2026-03-05', 'dept' => $admin],
            ['item' => 'CON-003', 'qty' => 8, 'date' => '2026-03-10', 'dept' => $ops],
            ['item' => 'CON-006', 'qty' => 15, 'date' => '2026-03-10', 'dept' => $ops],
            ['item' => 'CON-007', 'qty' => 20, 'date' => '2026-03-12', 'dept' => $finance],
            ['item' => 'CON-004', 'qty' => 25, 'date' => '2026-03-15', 'dept' => $finance],
            // April (current)
            ['item' => 'CON-001', 'qty' => 10, 'date' => '2026-04-02', 'dept' => $ops],
            ['item' => 'CON-002', 'qty' => 20, 'date' => '2026-04-02', 'dept' => $ops],
            ['item' => 'CON-006', 'qty' => 12, 'date' => '2026-04-03', 'dept' => $admin],
            // Semi-expendable issuances
            ['item' => 'SEM-001', 'qty' => 3, 'date' => '2026-01-25', 'dept' => $admin],
            ['item' => 'SEM-002', 'qty' => 2, 'date' => '2026-01-25', 'dept' => $ops],
            ['item' => 'SEM-003', 'qty' => 4, 'date' => '2026-02-01', 'dept' => $admin],
            ['item' => 'SEM-003', 'qty' => 3, 'date' => '2026-02-05', 'dept' => $ops],
            ['item' => 'SEM-004', 'qty' => 2, 'date' => '2026-02-15', 'dept' => $finance],
            ['item' => 'SEM-005', 'qty' => 1, 'date' => '2026-03-01', 'dept' => $admin],
            // PPE issuances
            ['item' => 'PPE-001', 'qty' => 2, 'date' => '2026-01-15', 'dept' => $admin],
            ['item' => 'PPE-001', 'qty' => 2, 'date' => '2026-01-18', 'dept' => $ops],
            ['item' => 'PPE-002', 'qty' => 3, 'date' => '2026-01-20', 'dept' => $admin],
            ['item' => 'PPE-002', 'qty' => 2, 'date' => '2026-01-22', 'dept' => $ops],
            ['item' => 'PPE-003', 'qty' => 1, 'date' => '2026-01-25', 'dept' => $admin],
            ['item' => 'PPE-003', 'qty' => 1, 'date' => '2026-02-01', 'dept' => $ops],
            ['item' => 'PPE-004', 'qty' => 1, 'date' => '2026-02-01', 'dept' => $admin],
            ['item' => 'PPE-004', 'qty' => 1, 'date' => '2026-02-10', 'dept' => $ops],
        ];

        $issSeq = 1;
        Issuance::withoutEvents(function () use ($issData, &$issSeq, $itemMap, $regional, $sc): void {
            foreach ($issData as $i) {
                $ref = '2026-01-'.str_pad((string) $issSeq++, 4, '0', STR_PAD_LEFT);
                Issuance::updateOrCreate(
                    ['reference_code' => $ref],
                    [
                        'item_id' => $itemMap[$i['item']]->id,
                        'office_id' => $regional->id,
                        'department_id' => $i['dept']->id,
                        'quantity' => $i['qty'],
                        'unit_cost' => $itemMap[$i['item']]->acquisitions()->first()?->unit_cost,
                        'issuance_date' => Carbon::parse($i['date']),
                        'issued_by' => $sc->id,
                    ],
                );
            }
        });

        // ─── Transfers ──────────────────────────────────────────
        // Regional → Satellite transfers
        $transferData = [
            ['item' => 'CON-004', 'qty' => 20, 'date' => '2026-02-20'],
            ['item' => 'CON-005', 'qty' => 10, 'date' => '2026-02-20'],
            ['item' => 'SEM-001', 'qty' => 2, 'date' => '2026-03-01'],
            ['item' => 'PPE-002', 'qty' => 2, 'date' => '2026-03-05'],
        ];

        $trSeq = 1;
        foreach ($transferData as $t) {
            $ref = 'PTR-2026-'.str_pad($trSeq++, 4, '0', STR_PAD_LEFT);
            Transfer::updateOrCreate(
                ['reference_code' => $ref],
                [
                    'item_id' => $itemMap[$t['item']]->id,
                    'from_office_id' => $regional->id,
                    'to_office_id' => $satellite->id,
                    'quantity' => $t['qty'],
                    'transfer_date' => Carbon::parse($t['date']),
                    'condition' => 'Serviceable',
                    'recorded_by' => $sc->id,
                ],
            );
        }

        // ─── Disposals ──────────────────────────────────────────
        $disposalData = [
            ['item' => 'PPE-003', 'qty' => 1, 'date' => '2026-03-15', 'reason' => 'Unserviceable — paper jam defect', 'type' => 'unserviceable'],
            ['item' => 'SEM-004', 'qty' => 1, 'date' => '2026-03-20', 'reason' => 'Damaged beyond repair', 'type' => 'lost_stolen_damaged'],
            ['item' => 'CON-003', 'qty' => 3, 'date' => '2026-03-25', 'reason' => 'Expired / dried out', 'type' => 'waste_sale'],
        ];

        $dspSeq = 1;
        foreach ($disposalData as $d) {
            $ref = 'DSP-2026-'.str_pad($dspSeq++, 4, '0', STR_PAD_LEFT);
            Disposal::updateOrCreate(
                ['reference_code' => $ref],
                [
                    'item_id' => $itemMap[$d['item']]->id,
                    'office_id' => $regional->id,
                    'quantity' => $d['qty'],
                    'disposal_date' => Carbon::parse($d['date']),
                    'reason' => $d['reason'],
                    'disposal_type' => $d['type'],
                    'recorded_by' => $sc->id,
                ],
            );
        }

        // ─── Requisitions ───────────────────────────────────────
        // JOE1 (Maria Santos) → UC: approved and compiled
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

        // JOE2 (Juan) → UC: approved
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

        // UC compiled req1 & req2 into a consolidated requisition → SC: approved
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

        // JOE1 → UC: pending request
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

        // JOE3 (Anna) → UC (finance): denied
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

        // ─── Distributions (UC → JOE) ──────────────────────────
        // Based on the approved compiled requisition
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

        // RSMI demo: link sample issuances to consolidated requisition (RIS No. vs Serial No.)
        foreach (['CON-001', 'CON-002'] as $consumableCode) {
            Issuance::query()
                ->where('item_id', $itemMap[$consumableCode]->id)
                ->whereDate('issuance_date', '2026-03-05')
                ->whereNull('requisition_id')
                ->limit(1)
                ->update(['requisition_id' => $compiledReq->id]);
        }

        // ─── Update reference series sequences ──────────────────
        \App\Models\ReferenceSeries::where('type', 'acquisition')->update(['next_sequence' => $acqSeq, 'last_generated_at' => now()]);
        \App\Models\ReferenceSeries::where('type', 'issuance')->update(['next_sequence' => $issSeq, 'last_generated_at' => now()]);
        \App\Models\ReferenceSeries::where('type', 'transfer')->update(['next_sequence' => $trSeq, 'last_generated_at' => now()]);
        \App\Models\ReferenceSeries::where('type', 'disposal')->update(['next_sequence' => $dspSeq, 'last_generated_at' => now()]);
        \App\Models\ReferenceSeries::where('type', 'requisition')->update(['next_sequence' => 6, 'last_generated_at' => now()]);
    }
}
