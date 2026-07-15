<?php

namespace App\Support;

use App\Models\Requisition;
use App\Models\RequisitionItem;
use Illuminate\Support\Collection;

class EmployeeRequisitionFulfillment
{
    public static function label(Requisition $requisition): ?string
    {
        $state = self::state($requisition);

        if ($state === null) {
            return null;
        }

        return RequisitionLineFulfillmentState::label($state);
    }

    public static function color(Requisition $requisition): string
    {
        $state = self::state($requisition);

        if ($state === null) {
            return 'gray';
        }

        return RequisitionLineFulfillmentState::color($state);
    }

    protected static function state(Requisition $requisition): ?string
    {
        if ($requisition->compiled_into_requisition_id === null) {
            return null;
        }

        $distributed = EmployeeRequisitionStatus::distributedTotal($requisition);
        $target = EmployeeRequisitionStatus::fulfillmentTargetTotal($requisition);

        if ($distributed > 0) {
            if ($distributed < $target) {
                return RequisitionLineFulfillmentState::PARTIALLY_ISSUED;
            }

            return RequisitionLineFulfillmentState::FULLY_ISSUED;
        }

        $requisition->loadMissing('compiledIntoRequisition.items');
        $compiled = $requisition->compiledIntoRequisition;

        if (! $compiled || $compiled->items->isEmpty()) {
            return null;
        }

        return self::aggregateLineStates($compiled->items);
    }

    /**
     * @param  Collection<int, RequisitionItem>  $items
     */
    protected static function aggregateLineStates(Collection $items): string
    {
        if ($items->isEmpty()) {
            return RequisitionLineFulfillmentState::IN_STOCK;
        }

        $states = $items
            ->map(fn (RequisitionItem $line): string => $line->fulfillmentState())
            ->unique()
            ->values();

        if ($states->contains(RequisitionLineFulfillmentState::BACKORDERED)) {
            return RequisitionLineFulfillmentState::BACKORDERED;
        }

        if ($states->contains(RequisitionLineFulfillmentState::PARTIALLY_ISSUED)) {
            return RequisitionLineFulfillmentState::PARTIALLY_ISSUED;
        }

        if ($states->every(fn (string $state): bool => $state === RequisitionLineFulfillmentState::FULLY_ISSUED)) {
            return RequisitionLineFulfillmentState::FULLY_ISSUED;
        }

        return RequisitionLineFulfillmentState::IN_STOCK;
    }
}
