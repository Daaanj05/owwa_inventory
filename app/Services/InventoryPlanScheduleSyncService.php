<?php

namespace App\Services;

use App\Models\Office;
use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class InventoryPlanScheduleSyncService
{
    /**
     * Expand UI schedule slots (category + planned date) into one line per active office.
     *
     * @param  array<int, array<string, mixed>>  $slots
     * @return array<int, array{office_id: int, item_category_id: int, planned_date: string}>
     */
    public function expandSlotsToLines(array $slots): array
    {
        $officeIds = Office::query()
            ->active()
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($officeIds === []) {
            throw ValidationException::withMessages([
                'schedule_slots' => 'Add at least one active office before creating an inventory schedule.',
            ]);
        }

        $lines = [];

        foreach ($slots as $slot) {
            $categoryId = (int) ($slot['item_category_id'] ?? 0);
            $plannedDate = $slot['planned_date'] ?? null;

            if ($categoryId <= 0 && blank($plannedDate)) {
                continue;
            }

            if ($categoryId <= 0 || blank($plannedDate)) {
                continue;
            }

            $planned = Carbon::parse($plannedDate)->toDateString();

            foreach ($officeIds as $officeId) {
                $lines[] = [
                    'office_id' => $officeId,
                    'item_category_id' => $categoryId,
                    'planned_date' => $planned,
                ];
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    public function syncPlanLines(PhysicalInventoryPlan $plan, array $slots): void
    {
        $desired = collect($this->expandSlotsToLines($slots))
            ->keyBy(fn (array $line): string => $line['office_id'].':'.$line['item_category_id']);

        $plan->loadMissing('lines');

        foreach ($plan->lines as $line) {
            $key = ((int) $line->office_id).':'.((int) $line->item_category_id);

            if ($desired->has($key)) {
                continue;
            }

            if ($line->physical_count_session_id !== null) {
                throw ValidationException::withMessages([
                    'schedule_slots' => 'Cannot remove a schedule date that already has a started physical count.',
                ]);
            }

            $line->delete();
        }

        $plan->unsetRelation('lines');
        $plan->load('lines');

        foreach ($desired as $key => $attrs) {
            /** @var PhysicalInventoryPlanLine|null $existing */
            $existing = $plan->lines->first(
                fn (PhysicalInventoryPlanLine $line): bool => (int) $line->office_id === (int) $attrs['office_id']
                    && (int) $line->item_category_id === (int) $attrs['item_category_id'],
            );

            if ($existing === null) {
                $plan->lines()->create($attrs);

                continue;
            }

            if ($existing->physical_count_session_id !== null) {
                continue;
            }

            $existing->update([
                'planned_date' => $attrs['planned_date'],
            ]);
        }
    }

    /**
     * Collapse stored lines into UI slots (one row per category + planned date).
     *
     * @return array<int, array{item_category_id: int, planned_date: string}>
     */
    public function slotsFromPlan(PhysicalInventoryPlan $plan): array
    {
        $plan->loadMissing('lines');

        return $plan->lines
            ->groupBy(function (PhysicalInventoryPlanLine $line): string {
                $date = $line->planned_date instanceof Carbon
                    ? $line->planned_date->toDateString()
                    : Carbon::parse($line->planned_date)->toDateString();

                return ((int) $line->item_category_id).'|'.$date;
            })
            ->map(function ($group): array {
                /** @var PhysicalInventoryPlanLine $first */
                $first = $group->first();
                $date = $first->planned_date instanceof Carbon
                    ? $first->planned_date->toDateString()
                    : Carbon::parse($first->planned_date)->toDateString();

                return [
                    'item_category_id' => (int) $first->item_category_id,
                    'planned_date' => $date,
                ];
            })
            ->values()
            ->all();
    }
}
