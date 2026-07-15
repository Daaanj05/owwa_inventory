<?php

namespace App\Support;

use App\Models\Distribution;
use App\Models\Requisition;
use App\Models\RequisitionSourceEndorsement;
use Illuminate\Support\Carbon;

class EmployeeRequisitionStatus
{
    public static function label(Requisition $requisition): string
    {
        if ($requisition->archived_at !== null && $requisition->isDraft()) {
            return 'Archived';
        }

        if ($requisition->status === Requisition::STATUS_DRAFT) {
            return 'Draft';
        }

        if ($requisition->status === Requisition::STATUS_REJECTED) {
            return 'Rejected';
        }

        if ($requisition->status === Requisition::STATUS_PENDING) {
            return 'Pending UC review';
        }

        if ($requisition->closed_at !== null) {
            return self::distributedTotal($requisition) < self::fulfillmentTargetTotal($requisition)
                ? 'Partially distributed — Closed'
                : 'Fully distributed — Closed';
        }

        $distributed = self::distributedTotal($requisition);
        $target = self::fulfillmentTargetTotal($requisition);

        if ($distributed > 0 && $distributed < $target) {
            return 'Partially distributed — Awaiting balance';
        }

        if ($requisition->hasBackorderedLines()) {
            return 'Awaiting regional stock';
        }

        if ($requisition->compiled_into_requisition_id !== null) {
            return 'Endorsed to SC';
        }

        if ($requisition->status === Requisition::STATUS_ACCEPTED) {
            return 'Reviewed';
        }

        return RequisitionStatus::label($requisition->status);
    }

    public static function color(Requisition $requisition): string
    {
        if ($requisition->archived_at !== null && $requisition->isDraft()) {
            return 'gray';
        }

        if ($requisition->status === Requisition::STATUS_DRAFT) {
            return 'gray';
        }

        if ($requisition->status === Requisition::STATUS_REJECTED) {
            return 'danger';
        }

        if ($requisition->status === Requisition::STATUS_PENDING) {
            return 'warning';
        }

        if ($requisition->closed_at !== null) {
            return 'success';
        }

        if ($requisition->compiled_into_requisition_id !== null) {
            return 'info';
        }

        return 'success';
    }

    public static function heroStatusClass(Requisition $requisition): string
    {
        if ($requisition->status === Requisition::STATUS_DRAFT || ($requisition->archived_at !== null && $requisition->isDraft())) {
            return 'owwa-pc-status-badge--incomplete';
        }

        if ($requisition->status === Requisition::STATUS_REJECTED) {
            return 'owwa-pc-status-badge--incomplete';
        }

        if ($requisition->closed_at !== null) {
            return 'owwa-pc-status-badge--complete';
        }

        if ($requisition->status === Requisition::STATUS_PENDING) {
            return 'owwa-pc-status-badge--progress';
        }

        return 'owwa-pc-status-badge--progress';
    }

    public static function requestedTotal(Requisition $requisition): int
    {
        $requisition->loadMissing('items');

        return (int) $requisition->items->sum('quantity');
    }

    /**
     * Qty the employee must receive before the request auto-closes.
     * After UC compile/endorse, this is the endorsed total (not the original request).
     */
    public static function fulfillmentTargetTotal(Requisition $requisition): int
    {
        if ($requisition->compiled_into_requisition_id !== null || $requisition->endorsed_at !== null) {
            $hasEndorsements = RequisitionSourceEndorsement::query()
                ->where('source_requisition_id', $requisition->id)
                ->exists();

            if ($hasEndorsements) {
                return (int) RequisitionSourceEndorsement::query()
                    ->where('source_requisition_id', $requisition->id)
                    ->sum('endorsed_quantity');
            }
        }

        return self::requestedTotal($requisition);
    }

    public static function remainingToFulfill(Requisition $requisition): int
    {
        return max(0, self::fulfillmentTargetTotal($requisition) - self::distributedTotal($requisition));
    }

    public static function distributedTotal(Requisition $requisition): int
    {
        return (int) Distribution::query()
            ->where('requisition_id', $requisition->id)
            ->sum('quantity');
    }

    public static function distributedTotalForItem(Requisition $requisition, int $itemId): int
    {
        return (int) Distribution::query()
            ->where('requisition_id', $requisition->id)
            ->where('item_id', $itemId)
            ->sum('quantity');
    }

    public static function fulfillmentTargetForItem(Requisition $requisition, int $itemId): int
    {
        if ($requisition->compiled_into_requisition_id !== null || $requisition->endorsed_at !== null) {
            $hasEndorsements = RequisitionSourceEndorsement::query()
                ->where('source_requisition_id', $requisition->id)
                ->where('item_id', $itemId)
                ->exists();

            if ($hasEndorsements) {
                return (int) RequisitionSourceEndorsement::query()
                    ->where('source_requisition_id', $requisition->id)
                    ->where('item_id', $itemId)
                    ->sum('endorsed_quantity');
            }
        }

        $requisition->loadMissing('items');

        return (int) $requisition->items
            ->where('item_id', $itemId)
            ->sum('quantity');
    }

    public static function remainingToFulfillForItem(Requisition $requisition, int $itemId): int
    {
        return max(0, self::fulfillmentTargetForItem($requisition, $itemId) - self::distributedTotalForItem($requisition, $itemId));
    }

    public static function latestDistributionDate(Requisition $requisition): ?Carbon
    {
        $date = Distribution::query()
            ->where('requisition_id', $requisition->id)
            ->max('distribution_date');

        return $date ? Carbon::parse($date) : null;
    }

    public static function formatSummary(int $distributed, int $target): string
    {
        return "Distributed {$distributed} of {$target}";
    }
}
