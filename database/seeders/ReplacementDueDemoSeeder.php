<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Support\DemoStockLedgerCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ReplacementDueDemoSeeder extends Seeder
{
    public function run(): void
    {
        $office = Office::query()->firstWhere('code', DemoStockLedgerCatalog::REGIONAL_OFFICE);
        $item = Item::query()->firstWhere('item_code', 'SEM-005');
        $custodian = User::query()->firstWhere('email', 'custodian@owwa.gov.ph');
        $employee = User::query()->firstWhere('email', 'maria@owwa.gov.ph');

        if ($office === null || $item === null || $custodian === null || $employee === null) {
            return;
        }

        $department = Department::query()
            ->where('office_id', $office->id)
            ->where('code', 'ADM')
            ->first()
            ?? Department::query()->where('office_id', $office->id)->first();

        if ($department === null) {
            return;
        }

        $issuedOn = Carbon::now()->subMonths(52)->toDateString();

        $requisition = Requisition::query()->updateOrCreate(
            ['reference_code' => 'REQ-EUL-DEMO-SEM-005'],
            [
                'office_id' => $office->id,
                'department_id' => $department->id,
                'requested_by' => $employee->id,
                'status' => Requisition::STATUS_ACCEPTED,
                'remarks' => 'Demo issuance so Replacement due has a unit nearing useful life.',
                'approved_by' => $custodian->id,
                'approved_at' => Carbon::parse($issuedOn),
            ],
        );

        RequisitionItem::query()->updateOrCreate(
            [
                'requisition_id' => $requisition->id,
                'item_id' => $item->id,
            ],
            ['quantity' => 1],
        );

        Issuance::query()->updateOrCreate(
            ['reference_code' => 'ISS-EUL-DEMO-SEM-005'],
            [
                'requisition_id' => $requisition->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => $department->id,
                'quantity' => 1,
                'unit_cost' => 1200,
                'amount' => 1200,
                'issuance_date' => $issuedOn,
                'estimated_useful_life' => '60 months',
                'property_number' => 'SPHV-EUL-DEMO-SEM-005',
                'issued_by' => $custodian->id,
                'issued_to' => $employee->id,
                'remarks' => 'Demo unit nearing the end of its useful life.',
            ],
        );
    }
}
