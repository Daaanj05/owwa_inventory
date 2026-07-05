<?php

namespace Database\Seeders;

use App\Events\IssuanceChanged;
use App\Events\RequisitionChanged;
use App\Support\DemoStockIntegrityVerifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;

class ExportTestingDemoSeeder extends Seeder
{
    public function run(): void
    {
        Event::fake([
            IssuanceChanged::class,
            RequisitionChanged::class,
        ]);

        $this->call([
            ItemCategorySeeder::class,
            EnsureOrgStructureSeeder::class,
            DemoDataSeeder::class,
            SemiExpendablePropertyClassSeeder::class,
            AcquisitionPaperworkDemoSeeder::class,
            DemoTransactionSeeder::class,
            DemoInventoryConnectionsSeeder::class,
            DemoDisposalSeeder::class,
            DemoPhysicalCountSeeder::class,
            PhysicalCountExportFixturesSeeder::class,
            PhysicalInventoryPlanDemoSeeder::class,
            IncidentReportDemoSeeder::class,
            DemoSemiPropertyClassSeeder::class,
        ]);

        app(DemoStockIntegrityVerifier::class)->assertDemoLedger();
    }
}
