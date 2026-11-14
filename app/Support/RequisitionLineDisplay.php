<?php

namespace App\Support;

use App\Models\Issuance;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use Illuminate\Support\Collection;

class RequisitionLineDisplay
{
    public static function identifierLabel(RequisitionItem $line): string
    {
        $line->loadMissing('item.category');
        $slug = $line->item?->category?->getTemplateSlug();

        return OwwaReferenceLabels::assetIdentifierLabel($slug);
    }

    public static function identifierValue(RequisitionItem $line): ?string
    {
        $line->loadMissing(['item.category', 'requisition']);

        $slug = $line->item?->category?->getTemplateSlug();
        $fromIssuance = self::latestIssuanceForLine($line)?->property_number;

        if (filled($fromIssuance)) {
            return (string) $fromIssuance;
        }

        if ($line->item === null) {
            return null;
        }

        if (OwwaReferenceLabels::usesPropertyNumber($slug)) {
            $catalogNumber = $line->item->catalogAssetIdentifier();

            return filled($catalogNumber) ? (string) $catalogNumber : null;
        }

        return OwwaReferenceLabels::assetIdentifierValue(
            $slug,
            null,
            $line->item->item_code,
        );
    }

    public static function latestIssuanceForLine(RequisitionItem $line): ?Issuance
    {
        $line->loadMissing('requisition');

        $requisition = $line->requisition;
        if (! $requisition) {
            return null;
        }

        return self::issuancesForRequisition($requisition)
            ->where('item_id', $line->item_id)
            ->sortByDesc('id')
            ->first();
    }

    /**
     * @return Collection<int, Issuance>
     */
    public static function issuancesForRequisition(Requisition $requisition): Collection
    {
        if ($requisition->isEmployeeRequest()) {
            return Issuance::query()
                ->with(['item.category'])
                ->where('requisition_id', $requisition->id)
                ->orderBy('id')
                ->get();
        }

        return Issuance::query()
            ->with(['item.category'])
            ->where(function ($query) use ($requisition): void {
                $query->where('consolidated_requisition_id', $requisition->id)
                    ->orWhere(function ($direct) use ($requisition): void {
                        $direct->where('requisition_id', $requisition->id)
                            ->whereNull('consolidated_requisition_id');
                    });
            })
            ->orderBy('id')
            ->get();
    }

    public static function hasIssuances(Requisition $requisition): bool
    {
        if ($requisition->isEmployeeRequest()) {
            return Issuance::query()
                ->where('requisition_id', $requisition->id)
                ->exists();
        }

        return Issuance::query()
            ->where(function ($query) use ($requisition): void {
                $query->where('consolidated_requisition_id', $requisition->id)
                    ->orWhere(function ($direct) use ($requisition): void {
                        $direct->where('requisition_id', $requisition->id)
                            ->whereNull('consolidated_requisition_id');
                    });
            })
            ->exists();
    }

    /**
     * @return Collection<int, string>
     */
    public static function relatedIssuanceSummaries(Requisition $requisition): Collection
    {
        return self::issuancesForRequisition($requisition)
            ->groupBy(fn (Issuance $issuance): string => $issuance->item?->category?->name ?? 'Other')
            ->flatMap(function (Collection $group, string $categoryName): Collection {
                return $group->map(function (Issuance $issuance) use ($categoryName): string {
                    $controlLabel = OwwaReferenceLabels::forIssuance($issuance);
                    $controlNumber = $issuance->controlNumber() ?? $issuance->reference_code;

                    return "{$categoryName} — {$controlLabel} {$controlNumber}";
                });
            })
            ->values();
    }

    public static function relatedIssuancesText(Requisition $requisition): ?string
    {
        $summaries = self::relatedIssuanceSummaries($requisition);

        return $summaries->isNotEmpty() ? $summaries->implode("\n") : null;
    }

    public static function mixedCategoriesNotice(): string
    {
        return 'This request has different item types. When you issue stock, each item will be recorded under its own category (Consumables, Semi-Expendable, or PPE).';
    }

    /**
     * @param  array<string, int>  $categoryCounts
     */
    public static function formatIssuanceCategorySummary(int $created, array $categoryCounts): string
    {
        $parts = collect($categoryCounts)
            ->map(fn (int $count, string $name): string => "{$name} ({$count})")
            ->values()
            ->all();

        $summary = $parts !== [] ? ' Records are under: '.implode(', ', $parts).'.' : '';

        return "Issued {$created} item(s).{$summary} Find them under Inventory → category → Issuances.";
    }
}
