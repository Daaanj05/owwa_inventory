<?php

namespace App\Services;

use App\Models\ItemCategory;
use App\Models\PhysicalCountSession;
use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;
use App\Models\User;
use App\Support\OfficeSignatoryDefaults;
use Illuminate\Validation\ValidationException;

class InventoryPlanStartCountService
{
    public function startCount(PhysicalInventoryPlanLine $line, User $user): PhysicalCountSession
    {
        if ($line->physical_count_session_id !== null) {
            throw ValidationException::withMessages([
                'physical_count_session_id' => 'A physical count session already exists for this schedule line.',
            ]);
        }

        $line->loadMissing(['plan', 'office', 'itemCategory']);

        $category = $line->itemCategory ?? ItemCategory::query()->find($line->item_category_id);

        $countType = match ($category?->getTemplateSlug()) {
            'ppe' => PhysicalCountSession::TYPE_RPCPPE,
            'semi_expendable' => PhysicalCountSession::TYPE_RPCSP,
            default => PhysicalCountSession::TYPE_RPCI,
        };

        $defaults = OfficeSignatoryDefaults::mergeNonBlank(
            OfficeSignatoryDefaults::forPhysicalCountSession($line->office_id),
            [],
        );

        $session = PhysicalCountSession::query()->create(array_merge($defaults, [
            'count_type' => $countType,
            'office_id' => $line->office_id,
            'item_category_id' => $line->item_category_id,
            'count_date' => $line->planned_date,
            'recorded_by' => $user->id,
        ]));

        $line->update(['physical_count_session_id' => $session->id]);

        $plan = $line->plan;

        if ($plan instanceof PhysicalInventoryPlan) {
            if ($plan->status === PhysicalInventoryPlan::STATUS_APPROVED) {
                $plan->update(['status' => PhysicalInventoryPlan::STATUS_IN_PROGRESS]);
            }

            if ($plan->status === PhysicalInventoryPlan::STATUS_DRAFT) {
                $plan->update([
                    'status' => PhysicalInventoryPlan::STATUS_IN_PROGRESS,
                    'approved_at' => $plan->approved_at ?? now(),
                ]);
            }
        }

        return $session;
    }
}
