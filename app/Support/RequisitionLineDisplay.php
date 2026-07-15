<?php

namespace App\Support;

use App\Models\Distribution;
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
        $line->loadMissing(['item.category', 'requisition.compiledIntoRequisition.issuances']);

        $slug = $line->item?->category?->getTemplateSlug();
        $propertyNumber = self::latestIssuanceForLine($line)?->property_number;

        return OwwaReferenceLabels::assetIdentifierValue(
            $slug,
            $propertyNumber,
            $line->item?->item_code,
        );
    }

    public static function latestIssuanceForLine(RequisitionItem $line): ?Issuance
    {
        $requisition = self::fulfillmentRequisition($line);

        if (! $requisition) {
            return null;
        }

        $requisition->loadMissing('issuances');

        return $requisition->issuances
            ->where('item_id', $line->item_id)
            ->sortByDesc('id')
            ->first();
    }

    /**
     * @return Collection<int, string>
     */
    public static function relatedIssuanceSummaries(Requisition $requisition): Collection
    {
        $fulfillmentRequisition = self::fulfillmentRequisitionForRequisition($requisition);

        if (! $fulfillmentRequisition) {
            return collect();
        }

        $fulfillmentRequisition->loadMissing(['issuances.item.category']);

        return $fulfillmentRequisition->issuances
            ->sortBy('id')
            ->groupBy(fn (Issuance $issuance): string => $issuance->item?->category?->name ?? 'Other')
            ->flatMap(function (Collection $group, string $categoryName): Collection {
                return $group->map(function (Issuance $issuance) use ($categoryName): string {
                    $controlLabel = OwwaReferenceLabels::forIssuance($issuance);

                    return "{$categoryName} — {$controlLabel} {$issuance->reference_code}";
                });
            })
            ->values();
    }

    public static function relatedIssuancesText(Requisition $requisition): ?string
    {
        $summaries = self::relatedIssuanceSummaries($requisition);

        return $summaries->isNotEmpty() ? $summaries->implode("\n") : null;
    }

    public static function distributedQuantity(RequisitionItem $line): int
    {
        $line->loadMissing('requisition');

        if (! $line->requisition) {
            return 0;
        }

        return (int) Distribution::query()
            ->where('requisition_id', $line->requisition->id)
            ->where('item_id', $line->item_id)
            ->sum('quantity');
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

    protected static function fulfillmentRequisition(RequisitionItem $line): ?Requisition
    {
        $line->loadMissing('requisition.compiledIntoRequisition');

        return self::fulfillmentRequisitionForRequisition($line->requisition);
    }

    protected static function fulfillmentRequisitionForRequisition(?Requisition $requisition): ?Requisition
    {
        if (! $requisition) {
            return null;
        }

        $requisition->loadMissing('compiledIntoRequisition');

        return $requisition->compiledIntoRequisition ?? $requisition;
    }
}
