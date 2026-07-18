<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Services\PhysicalCountPreloadService;
use App\Support\DemoStockLedgerCatalog;
use App\Support\PhysicalCountPropertyClassResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoPhysicalCountSeeder extends Seeder
{
    public function run(): void
    {
        $office = Office::query()->firstWhere('code', DemoStockLedgerCatalog::REGIONAL_OFFICE);
        $custodian = User::query()->where('email', 'custodian@owwa.gov.ph')->first();

        if (! $office || ! $custodian) {
            return;
        }

        $consumables = ItemCategory::query()->where('name', 'Consumables')->first();
        $semi = ItemCategory::query()->where('name', 'Semi-Expendable')->first();
        $ppe = ItemCategory::query()->where('name', 'Property, Plant and Equipment')->first();

        if (! $consumables || ! $semi || ! $ppe) {
            return;
        }

        $stockService = app(InventoryStockService::class);

        $this->seedRpciSession($consumables, $office, $custodian, $stockService);
        $this->seedRpcspSession($semi, $office, $custodian);
        $this->seedRpcppeSession($ppe, $office, $custodian);
    }

    protected function seedRpciSession(
        ItemCategory $category,
        Office $office,
        User $custodian,
        InventoryStockService $stockService,
    ): void {
        $session = PhysicalCountSession::updateOrCreate(
            ['reference_code' => 'PC-DEMO-RPCI-2026'],
            $this->sessionDefaults($category, $office, $custodian, PhysicalCountSession::TYPE_RPCI, [
                'inventory_type' => \App\Support\ConsumableInventoryType::OfficeSupplies,
                'inventory_type_label' => 'Office Supplies Inventory',
            ]),
        );

        PhysicalCountLine::query()->where('physical_count_session_id', $session->id)->delete();

        $items = Item::query()
            ->where('item_category_id', $category->id)
            ->whereIn('item_code', DemoStockLedgerCatalog::coreConsumableCodes())
            ->orderBy('item_code')
            ->get();

        foreach ($items as $item) {
            $balance = $stockService->getStock($item->id, $office->id);

            if ($balance <= 0) {
                continue;
            }

            PhysicalCountLine::query()->create([
                'physical_count_session_id' => $session->id,
                'item_id' => $item->id,
                'article' => $item->name,
                'stock_number' => $item->item_code,
                'unit_of_measure' => $item->unit,
                'balance_per_card' => $balance,
                'on_hand_count' => $balance,
            ]);
        }
    }

    protected function seedRpcspSession(ItemCategory $category, Office $office, User $custodian): void
    {
        $session = PhysicalCountSession::updateOrCreate(
            ['reference_code' => 'PC-DEMO-RPCSP-2026'],
            $this->sessionDefaults($category, $office, $custodian, PhysicalCountSession::TYPE_RPCSP, [
                'book_list_loaded' => false,
            ]),
        );

        PhysicalCountLine::query()->where('physical_count_session_id', $session->id)->delete();

        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session->fresh());

        $incidentItem = Item::query()->where('item_code', 'SEM-004')->value('id');

        foreach ($session->fresh()->lines as $line) {
            $onHand = (int) $line->balance_per_card;

            if ($incidentItem !== null && (int) $line->item_id === (int) $incidentItem && $onHand > 0) {
                $onHand = max(0, $onHand - 1);
            }

            $line->update(['on_hand_count' => $onHand]);
        }

        PhysicalCountPropertyClassResolver::syncSession($session->fresh(['lines.item']));
    }

    protected function seedRpcppeSession(ItemCategory $category, Office $office, User $custodian): void
    {
        $session = PhysicalCountSession::updateOrCreate(
            ['reference_code' => 'PC-DEMO-RPCPPE-2026'],
            $this->sessionDefaults($category, $office, $custodian, PhysicalCountSession::TYPE_RPCPPE, [
                'book_list_loaded' => false,
            ]),
        );

        PhysicalCountLine::query()->where('physical_count_session_id', $session->id)->delete();

        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session->fresh());

        foreach ($session->fresh()->lines as $line) {
            $line->update(['on_hand_count' => (int) $line->balance_per_card]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function sessionDefaults(
        ItemCategory $category,
        Office $office,
        User $custodian,
        string $countType,
        array $extra = [],
    ): array {
        return array_merge([
            'count_type' => $countType,
            'status' => PhysicalCountSession::STATUS_COMPLETE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => Carbon::parse(DemoStockLedgerCatalog::PHYSICAL_COUNT_DATE),
            'fund_cluster' => '01',
            'accountable_officer_name' => 'Marita C. Ablis',
            'accountable_officer_designation' => 'Supply Officer',
            'date_of_assumption' => Carbon::parse('2026-01-01'),
            'certified_by_printed_name' => 'Maria Santos',
            'approved_by_printed_name' => 'Roberto Cruz',
            'verified_by_printed_name' => 'COA Rep. Ana Reyes',
            'recorded_by' => $custodian->id,
            'completed_at' => now(),
        ], $extra);
    }
}
