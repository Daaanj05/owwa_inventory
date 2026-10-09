<?php

namespace App\Services;

use App\Models\Issuance;
use App\Support\InventoryCategoryOptions;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Support\Collection;

class SemiExpendableEulAnalyticsService
{
    public const ACTION_AWAITING_REVIEW = 'awaiting_review';

    public const ACTION_ISSUE_FROM_STOCK = 'issue_from_stock';

    public const ACTION_PURCHASE = 'purchase';

    public function __construct(
        protected InventoryStockService $stockService,
    ) {}

    /**
     * Semi-expendable issuances nearing or past useful life (replacement / review signals).
     *
     * @param  array<int>  $officeIds
     * @return Collection<int, object{
     *   issuance_id:int,
     *   item_id:int,
     *   office_id:int|null,
     *   item_name:string,
     *   property_number:string|null,
     *   reference_code:string|null,
     *   issued_to_name:string|null,
     *   estimated_useful_life:string|null,
     *   eul_expires_at:string|null,
     *   status:string,
     *   status_label:string,
     *   days_until_expiry:int|null,
     *   unissued_stock:int,
     *   action:string,
     *   action_label:string
     * }>
     */
    public function getReviewRows(array $officeIds = [], int $limit = 25): Collection
    {
        $semiCategoryIds = InventoryCategoryOptions::categoryIdsForSlug('semi_expendable')->all();
        if ($semiCategoryIds === []) {
            return collect();
        }

        $issuances = Issuance::query()
            ->with(['item.category', 'issuedTo'])
            ->whereNotNull('eul_expires_at')
            ->when($officeIds !== [], fn ($q) => $q->whereIn('office_id', $officeIds))
            ->whereHas('item', fn ($q) => $q->whereIn('item_category_id', $semiCategoryIds))
            ->orderBy('eul_expires_at')
            ->limit(max($limit * 4, 50))
            ->get();

        return $issuances
            ->map(function (Issuance $issuance): ?object {
                $status = SemiExpendableUsefulLife::statusForIssuance($issuance);
                if (! in_array($status, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true)) {
                    return null;
                }

                $issuedTo = $issuance->issuedTo;
                $itemId = (int) $issuance->item_id;
                $officeId = $issuance->office_id !== null ? (int) $issuance->office_id : null;
                $unissuedStock = ($itemId > 0 && $officeId !== null)
                    ? $this->stockService->getActiveRestockStock($itemId, $officeId)
                    : 0;
                [$action, $actionLabel] = $this->replacementAction($issuance, $unissuedStock);

                return (object) [
                    'issuance_id' => (int) $issuance->id,
                    'item_id' => $itemId,
                    'office_id' => $officeId,
                    'item_name' => (string) ($issuance->item?->name ?? 'Item'),
                    'property_number' => filled($issuance->property_number) ? (string) $issuance->property_number : null,
                    'reference_code' => filled($issuance->reference_code) ? (string) $issuance->reference_code : null,
                    'issued_to_name' => $issuedTo?->name,
                    'estimated_useful_life' => filled($issuance->estimated_useful_life)
                        ? (string) $issuance->estimated_useful_life
                        : null,
                    'eul_expires_at' => $issuance->eul_expires_at?->toDateString(),
                    'status' => $status,
                    'status_label' => SemiExpendableUsefulLife::statusLabel($status),
                    'days_until_expiry' => SemiExpendableUsefulLife::daysUntilExpiry($issuance),
                    'unissued_stock' => $unissuedStock,
                    'action' => $action,
                    'action_label' => $actionLabel,
                ];
            })
            ->filter()
            ->sortBy([
                fn (object $row): int => $row->status === SemiExpendableUsefulLife::STATUS_EXPIRED ? 0 : 1,
                fn (object $row): string => $row->eul_expires_at ?? '9999-12-31',
            ])
            ->values()
            ->take($limit);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function replacementAction(Issuance $issuance, int $unissuedStock): array
    {
        if ($issuance->useful_life_condition !== Issuance::USEFUL_LIFE_NEEDS_REPLACEMENT) {
            return [self::ACTION_AWAITING_REVIEW, 'Awaiting review'];
        }

        if ($unissuedStock > 0) {
            return [self::ACTION_ISSUE_FROM_STOCK, 'Issue from stock ('.number_format($unissuedStock).' on hand)'];
        }

        return [self::ACTION_PURCHASE, 'Purchase'];
    }
}
