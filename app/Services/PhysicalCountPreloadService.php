<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PhysicalCountPreloadService
{
    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function preloadFromCustodyRecords(PhysicalCountSession $session): array
    {
        if (! $session->supportsQrScanning()) {
            throw new InvalidArgumentException('QR preload is only available for PPE and semi-expendable physical count sessions.');
        }

        $session->loadMissing(['office', 'itemCategory']);

        $fromUnits = $this->preloadFromInventoryUnits($session);

        $result = ($fromUnits['created'] > 0 || $fromUnits['updated'] > 0)
            ? $fromUnits
            : $this->preloadFromIssuances($session);

        $session->update(['book_list_loaded' => true]);

        return $result;
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
                ->where('status', InventoryUnit::STATUS_IN_STOCK)
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
                ->filter(function (InventoryUnit $unit) use ($categorySlug): bool {
                    return $unit->item?->category?->getTemplateSlug() === $categorySlug;
                });

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

                $existing = PhysicalCountLine::query()
                    ->where('physical_count_session_id', $session->id)
                    ->where('property_number', $propertyNumber)
                    ->first();

                $lineData = [
                    'item_id' => $item->id,
                    'article' => $unit->article ?? $item->name,
                    'description' => $unit->description ?? $item->description,
                    'stock_number' => $unit->stock_number ?? $item->item_code,
                    'unit_of_measure' => $unit->unit_of_measure ?? $item->unit,
                    'balance_per_card' => 1,
                ];

                if ($existing !== null) {
                    $existing->update($lineData);
                    $updated++;

                    continue;
                }

                PhysicalCountLine::query()->create([
                    ...$lineData,
                    'physical_count_session_id' => $session->id,
                    'property_number' => $propertyNumber,
                    'on_hand_count' => 0,
                ]);
                $created++;
            }
        });

        return compact('created', 'updated', 'skipped');
    }

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    protected function preloadFromIssuances(PhysicalCountSession $session): array
    {
        $categorySlug = $session->templateSlug();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($session, $categorySlug, &$created, &$updated, &$skipped): void {
            $issuances = Issuance::query()
                ->with(['item.category', 'office'])
                ->where('office_id', $session->office_id)
                ->whereNotNull('property_number')
                ->where('property_number', '!=', '')
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

                $balance = max(1, (int) $issuance->quantity);
                $existing = PhysicalCountLine::query()
                    ->where('physical_count_session_id', $session->id)
                    ->where('property_number', $propertyNumber)
                    ->first();

                if ($existing !== null) {
                    $existing->update([
                        'item_id' => $item->id,
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
                    'property_number' => $propertyNumber,
                    'unit_of_measure' => $item->unit,
                    'balance_per_card' => $balance,
                    'on_hand_count' => 0,
                ]);
                $created++;
            }
        });

        return compact('created', 'updated', 'skipped');
    }
}
