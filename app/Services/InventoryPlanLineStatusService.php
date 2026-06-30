<?php

namespace App\Services;

use App\Models\PhysicalCountSession;
use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;

class InventoryPlanLineStatusService
{
    public function statusForLine(PhysicalInventoryPlanLine $line): string
    {
        $line->loadMissing('physicalCountSession');

        $session = $line->physicalCountSession;

        if ($session?->isComplete()) {
            return PhysicalInventoryPlanLine::STATUS_COMPLETE;
        }

        if ($session !== null) {
            return PhysicalInventoryPlanLine::STATUS_IN_PROGRESS;
        }

        if ($line->planned_date !== null && $line->planned_date->isPast() && ! $line->planned_date->isToday()) {
            return PhysicalInventoryPlanLine::STATUS_OVERDUE;
        }

        return PhysicalInventoryPlanLine::STATUS_PENDING;
    }

    public function syncPlanStatus(PhysicalInventoryPlan $plan): void
    {
        $plan->loadMissing('lines.physicalCountSession');

        if ($plan->lines->isEmpty()) {
            return;
        }

        $allComplete = $plan->lines->every(
            fn (PhysicalInventoryPlanLine $line): bool => $line->physicalCountSession?->isComplete() ?? false
        );

        if ($allComplete) {
            if ($plan->status !== PhysicalInventoryPlan::STATUS_COMPLETED) {
                $plan->update(['status' => PhysicalInventoryPlan::STATUS_COMPLETED]);
            }

            return;
        }

        $anySession = $plan->lines->contains(
            fn (PhysicalInventoryPlanLine $line): bool => $line->physical_count_session_id !== null
        );

        if ($anySession && ! in_array($plan->status, [
            PhysicalInventoryPlan::STATUS_IN_PROGRESS,
            PhysicalInventoryPlan::STATUS_COMPLETED,
        ], true)) {
            $plan->update(['status' => PhysicalInventoryPlan::STATUS_IN_PROGRESS]);
        }
    }

    public function syncForSession(PhysicalCountSession $session): void
    {
        if (! $session->isComplete()) {
            return;
        }

        $line = PhysicalInventoryPlanLine::query()
            ->where('physical_count_session_id', $session->id)
            ->with('plan.lines.physicalCountSession')
            ->first();

        if ($line === null) {
            return;
        }

        $this->syncPlanStatus($line->plan);
    }
}
