<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemStockBucket;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Support\ItemPropertyClass;
use Database\Seeders\PhysicalCountExportDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhysicalCountExportDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_three_complete_export_sessions(): void
    {
        $this->seed(PhysicalCountExportDemoSeeder::class);

        $rpci = PhysicalCountSession::query()->where('reference_code', 'PC-EXPORT-RPCI-2026')->first();
        $rpcsp = PhysicalCountSession::query()->where('reference_code', 'PC-EXPORT-RPCSP-2026')->first();
        $rpcppe = PhysicalCountSession::query()->where('reference_code', 'PC-EXPORT-RPCPPE-2026')->first();

        $this->assertNotNull($rpci);
        $this->assertNotNull($rpcsp);
        $this->assertNotNull($rpcppe);

        $this->assertTrue($rpci->isComplete());
        $this->assertTrue($rpcsp->isComplete());
        $this->assertTrue($rpcppe->isComplete());

        $this->assertGreaterThanOrEqual(70, $rpci->lines()->count());
        $this->assertGreaterThanOrEqual(70, $rpcsp->lines()->count());
        $this->assertGreaterThanOrEqual(70, $rpcppe->lines()->count());
    }

    public function test_rpcsp_seeded_lines_use_one_property_number_per_item_with_quantity(): void
    {
        $this->seed(PhysicalCountExportDemoSeeder::class);

        $session = PhysicalCountSession::query()
            ->where('reference_code', 'PC-EXPORT-RPCSP-2026')
            ->with('lines.item')
            ->firstOrFail();

        $officeEquipmentLines = $session->lines
            ->filter(fn (PhysicalCountLine $line): bool => ItemPropertyClass::resolveForExport($line->item?->property_class) === ItemPropertyClass::OfficeEquipment);

        $this->assertGreaterThanOrEqual(25, $officeEquipmentLines->count());

        $duplicatePropertyNumbers = $session->lines
            ->groupBy('property_number')
            ->filter(fn ($group): bool => $group->count() > 1);

        $this->assertCount(0, $duplicatePropertyNumbers);

        $sampleItem = Item::query()->where('item_code', 'SEM-EXPORT-001')->firstOrFail();
        $acquisitionCost = $sampleItem->acquisitions()->orderBy('id')->value('unit_cost');
        $bucket = ItemStockBucket::findForItemCost((int) $sampleItem->id, $acquisitionCost !== null ? (float) $acquisitionCost : null);
        $this->assertNotNull($bucket?->property_number);

        $matchingLine = $session->lines->firstWhere('item_id', $sampleItem->id);
        $this->assertNotNull($matchingLine);
        $this->assertSame($bucket->property_number, $matchingLine->property_number);
        $this->assertGreaterThan(1, $matchingLine->balance_per_card);
    }
}
