<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Support\ItemPropertyClass;
use App\Support\PhysicalCountPropertyClassResolver;
use App\Support\PpePropertyType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PhysicalCountPreloadService
{
    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function preloadFromCustodyRecords(PhysicalCountSession $session): array
    {
        if (! $session->supportsUnitQrScanning()) {
            throw new InvalidArgumentException('Custody preload is only available for PPE and semi-expendable physical count sessions.');
        }

        $session->loadMissing(['office', 'itemCategory']);

        $result = $this->preloadFromInventoryUnits($session);

        if ($result['created'] === 0 && $result['updated'] === 0) {
            $result = $this->preloadFromIssuances($session);
        }

        $session->update(['book_list_loaded' => true]);

        $this->syncPropertyClassFields($session->fresh());

        return $result;
    }

    /**
     * Load RPCI lines from Stock Card balances for the session office + inventory type.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function preloadFromStockBalances(PhysicalCountSession $session): array
    {
        if (! $session->supportsStockQrScanning()) {
            throw new InvalidArgumentException('Stock preload is only available for consumable (RPCI) physical count sessions.');
        }

        $session->loadMissing(['office', 'itemCategory']);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $stockService = app(InventoryStockService::class);

        DB::transaction(function () use ($session, $stockService, &$created, &$updated, &$skipped): void {
            $query = \App\Models\Item::query()
                ->active()
                ->whereHas('category', function ($categoryQuery): void {
                    $categoryQuery->whereNull('archived_at');
                })
                ->orderBy('name');

            if ($session->item_category_id) {
                $query->where('item_category_id', $session->item_category_id);
            }

            if (filled($session->inventory_type)) {
                $query->where('inventory_type', $session->inventory_type);
            }

            foreach ($query->get() as $item) {
                if ($item->category?->getTemplateSlug() !== 'consumables') {
                    $skipped++;

                    continue;
                }

                if (! $stockService->hasInventoryActivity((int) $item->id, (int) $session->office_id)) {
                    $skipped++;

                    continue;
                }

                $balance = max(0, $stockService->getStock((int) $item->id, (int) $session->office_id));
                $existing = PhysicalCountLine::query()
                    ->where('physical_count_session_id', $session->id)
                    ->where('item_id', $item->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $existing->update([
                        'article' => $item->name,
                        'description' => $item->description,
                        'stock_number' => $item->item_code,
                        'unit_of_measure' => $item->unit,
                        'balance_per_card' => $balance,
                    ]);
                    $updated++;

                    continue;
                }

                PhysicalCountLine::query()->create([
                    'physical_count_session_id' => $session->id,
                    'item_id' => $item->id,
                    'article' => $item->name,
                    'description' => $item->description,
                    'stock_number' => $item->item_code,
                    'unit_of_measure' => $item->unit,
                    'balance_per_card' => $balance,
                    'on_hand_count' => 0,
                ]);
                $created++;
            }
        });

        $session->update(['book_list_loaded' => true]);

        return compact('created', 'updated', 'skipped');
    }

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function preloadFromIssuances(PhysicalCountSession $session): array
    {
        $categorySlug = $session->templateSlug();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($session, $categorySlug, &$created, &$updated, &$skipped): void {
            $issuances = Issuance::query()
                ->with(['item.category'])
                ->where('office_id', $session->office_id)
                ->whereNotNull('property_number')
                ->whereHas('item.category', function ($query): void {
                    $query->whereNull('archived_at');
                })
                ->whereHas('item', function ($query) use ($session): void {
                    $query->active();
                    if ($session->item_category_id) {
                        $query->where('item_category_id', $session->item_category_id);
                    }
                })
                ->orderBy('property_number')
                ->get()
                ->filter(function (Issuance $issuance) use ($categorySlug): bool {
                    return $issuance->item?->category?->getTemplateSlug() === $categorySlug;
                });

            if ($session->count_type === PhysicalCountSession::TYPE_RPCSP) {
                /** @var array<string, array{issuance: Issuance, count: int}> $grouped */
                $grouped = [];

                foreach ($issuances as $issuance) {
                    $propertyNumber = trim((string) $issuance->property_number);
                    if ($propertyNumber === '' || $issuance->item === null) {
                        $skipped++;

                        continue;
                    }

                    if (! isset($grouped[$propertyNumber])) {
                        $grouped[$propertyNumber] = ['issuance' => $issuance, 'count' => 0];
                    }

                    $grouped[$propertyNumber]['count'] += max(1, (int) $issuance->quantity);
                }

                foreach ($grouped as $propertyNumber => $entry) {
                    $result = $this->upsertPhysicalCountLine(
                        $session,
                        $propertyNumber,
                        $this->lineDataFromIssuance($entry['issuance']),
                        $entry['count'],
                    );

                    $this->tallyUpsertResult($result, $created, $updated);
                }

                return;
            }

            foreach ($issuances as $issuance) {
                $propertyNumber = trim((string) $issuance->property_number);
                if ($propertyNumber === '') {
                    $skipped++;

                    continue;
                }

                $item = $issuance->item;
                if ($item === null) {
                    $skipped++;

                    continue;
                }

                $result = $this->upsertPhysicalCountLine(
                    $session,
                    $propertyNumber,
                    $this->lineDataFromIssuance($issuance),
                    max(1, (int) $issuance->quantity),
                );

                $this->tallyUpsertResult($result, $created, $updated);
            }
        });

        return compact('created', 'updated', 'skipped');
    }

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function preloadFromInventoryUnits(PhysicalCountSession $session): array
    {
        $categorySlug = $session->templateSlug();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($session, $categorySlug, &$created, &$updated, &$skipped): void {
            $units = InventoryUnit::query()
                ->with(['item.category'])
                ->where('office_id', $session->office_id)
                ->whereIn('status', InventoryUnit::accountableStatuses())
                ->whereHas('item.category', function ($query): void {
                    $query->whereNull('archived_at');
                })
                ->whereHas('item', function ($query) use ($session): void {
                    $query->active();
                    if ($session->item_category_id) {
                        $query->where('item_category_id', $session->item_category_id);
                    }
                })
                ->orderBy('property_number')
                ->get()
                ->filter(function (InventoryUnit $unit) use ($categorySlug, $session): bool {
                    if ($unit->item?->category?->getTemplateSlug() !== $categorySlug) {
                        return false;
                    }

                    if ($session->count_type === PhysicalCountSession::TYPE_RPCPPE && filled($session->ppe_type)) {
                        return PpePropertyType::resolveForExport($unit->item?->ppe_type)
                            === PpePropertyType::resolveForExport($session->ppe_type);
                    }

                    if ($session->count_type === PhysicalCountSession::TYPE_RPCSP && filled($session->property_class)) {
                        return ItemPropertyClass::resolveForExport($unit->item?->property_class)
                            === ItemPropertyClass::resolveForExport($session->property_class);
                    }

                    return true;
                });

            if ($session->count_type === PhysicalCountSession::TYPE_RPCSP) {
                /** @var array<string, array{unit: InventoryUnit, count: int}> $grouped */
                $grouped = [];

                foreach ($units as $unit) {
                    $propertyNumber = trim((string) $unit->property_number);
                    if ($propertyNumber === '' || $unit->item === null) {
                        $skipped++;

                        continue;
                    }

                    if (! isset($grouped[$propertyNumber])) {
                        $grouped[$propertyNumber] = ['unit' => $unit, 'count' => 0];
                    }

                    $grouped[$propertyNumber]['count']++;
                }

                foreach ($grouped as $propertyNumber => $entry) {
                    $result = $this->upsertPhysicalCountLine(
                        $session,
                        $propertyNumber,
                        $this->lineDataFromUnit($entry['unit']),
                        $entry['count'],
                    );

                    $this->tallyUpsertResult($result, $created, $updated);
                }

                return;
            }

            foreach ($units as $unit) {
                $propertyNumber = trim((string) $unit->property_number);
                if ($propertyNumber === '') {
                    $skipped++;

                    continue;
                }

                $item = $unit->item;
                if ($item === null) {
                    $skipped++;

                    continue;
                }

                $result = $this->upsertPhysicalCountLine(
                    $session,
                    $propertyNumber,
                    $this->lineDataFromUnit($unit),
                    1,
                );

                $this->tallyUpsertResult($result, $created, $updated);
            }
        });

        return compact('created', 'updated', 'skipped');
    }

    /**
     * @return array<string, mixed>
     */
    protected function lineDataFromUnit(InventoryUnit $unit): array
    {
        $item = $unit->item;

        return [
            'item_id' => $item?->id ?? $unit->item_id,
            'article' => $unit->article ?? $item?->name,
            'description' => $unit->description ?? $item?->description,
            'stock_number' => $unit->stock_number ?? $item?->item_code,
            'unit_of_measure' => $unit->unit_of_measure ?? $item?->unit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function lineDataFromIssuance(Issuance $issuance): array
    {
        $item = $issuance->item;

        return [
            'item_id' => $item?->id ?? $issuance->item_id,
            'article' => $item?->name,
            'description' => $item?->description,
            'stock_number' => $item?->item_code,
            'unit_of_measure' => $item?->unit,
        ];
    }

    /**
     * @param  array<string, mixed>  $lineData
     * @return 'created'|'updated'
     */
    protected function upsertPhysicalCountLine(
        PhysicalCountSession $session,
        string $propertyNumber,
        array $lineData,
        int $balanceIncrement,
    ): string {
        $existing = PhysicalCountLine::query()
            ->where('physical_count_session_id', $session->id)
            ->where('property_number', $propertyNumber)
            ->first();

        if ($existing !== null) {
            $existing->update([
                ...$lineData,
                'balance_per_card' => (int) $existing->balance_per_card + $balanceIncrement,
            ]);

            return 'updated';
        }

        PhysicalCountLine::query()->create([
            ...$lineData,
            'physical_count_session_id' => $session->id,
            'property_number' => $propertyNumber,
            'balance_per_card' => $balanceIncrement,
            'on_hand_count' => 0,
        ]);

        return 'created';
    }

    protected function tallyUpsertResult(string $result, int &$created, int &$updated): void
    {
        if ($result === 'created') {
            $created++;
        } else {
            $updated++;
        }
    }

    protected function syncPropertyClassFields(PhysicalCountSession $session): void
    {
        PhysicalCountPropertyClassResolver::syncSession($session);
    }
}
