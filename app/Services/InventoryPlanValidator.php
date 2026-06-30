<?php

namespace App\Services;

use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class InventoryPlanValidator
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>|null  $lines
     */
    public function validateForSave(array $data, ?PhysicalInventoryPlan $existing = null, ?array $lines = null): void
    {
        if (blank($data['title'] ?? null)) {
            throw ValidationException::withMessages([
                'title' => 'Enter a schedule title.',
            ]);
        }

        if (blank($data['cut_off_date'] ?? null)) {
            throw ValidationException::withMessages([
                'cut_off_date' => 'Select a cut-off date.',
            ]);
        }

        $today = now()->startOfDay();
        $cutOff = $this->parseDate($data['cut_off_date']);

        if ($cutOff->lt($today)) {
            throw ValidationException::withMessages([
                'cut_off_date' => 'Cut-off date cannot be in the past.',
            ]);
        }

        $lineRows = $lines ?? [];

        if ($lineRows !== []) {
            $this->validateLines($lineRows, $cutOff, $today, $existing);
        }

        if (filled($data['coa_submitted_at'] ?? null) && $lineRows !== []) {
            $firstPlanned = collect($lineRows)
                ->pluck('planned_date')
                ->filter()
                ->map(fn (mixed $date): Carbon => $this->parseDate($date))
                ->sort()
                ->first();

            if ($firstPlanned !== null) {
                $latestAllowedCoa = $firstPlanned->copy()->subDays(10);

                if ($this->parseDate($data['coa_submitted_at'])->gt($latestAllowedCoa)) {
                    throw ValidationException::withMessages([
                        'coa_submitted_at' => 'COA submission date must be at least 10 days before the first scheduled count.',
                    ]);
                }
            }
        }
    }

    public function validateCanComplete(PhysicalInventoryPlan $plan): void
    {
        $plan->loadMissing('lines.physicalCountSession');

        foreach ($plan->lines as $line) {
            if (! $line->physicalCountSession?->isComplete()) {
                throw ValidationException::withMessages([
                    'status' => 'Complete every scheduled count before marking the schedule completed.',
                ]);
            }
        }
    }

    public function validateCanDeleteLine(PhysicalInventoryPlanLine $line): void
    {
        $line->loadMissing('physicalCountSession');

        if ($line->physical_count_session_id !== null) {
            throw ValidationException::withMessages([
                'lines' => 'Cannot remove a schedule line that already has a physical count session.',
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function validateLines(array $lines, Carbon $cutOff, Carbon $today, ?PhysicalInventoryPlan $existing): void
    {
        $seen = [];

        foreach ($lines as $index => $line) {
            $officeId = (int) ($line['office_id'] ?? 0);
            $categoryId = (int) ($line['item_category_id'] ?? 0);
            $plannedDate = $line['planned_date'] ?? null;

            if ($officeId <= 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.office_id" => 'Select an office for each schedule line.',
                ]);
            }

            if ($categoryId <= 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.item_category_id" => 'Select a category for each schedule line.',
                ]);
            }

            if (blank($plannedDate)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.planned_date" => 'Select a planned date for each schedule line.',
                ]);
            }

            $planned = $this->parseDate($plannedDate);

            if ($planned->lt($today)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.planned_date" => 'Planned date cannot be in the past.',
                ]);
            }

            if ($planned->gt($cutOff)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.planned_date" => 'Planned date must be on or before the cut-off date.',
                ]);
            }

            $key = "{$officeId}:{$categoryId}";

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.office_id" => 'Each office and category pair may only appear once per schedule.',
                ]);
            }

            $seen[$key] = true;
        }

        if ($existing !== null) {
            foreach ($lines as $index => $line) {
                $lineId = (int) ($line['id'] ?? 0);
                $officeId = (int) ($line['office_id'] ?? 0);
                $categoryId = (int) ($line['item_category_id'] ?? 0);

                $exists = PhysicalInventoryPlanLine::query()
                    ->where('physical_inventory_plan_id', $existing->id)
                    ->where('office_id', $officeId)
                    ->where('item_category_id', $categoryId)
                    ->when($lineId > 0, fn ($query) => $query->whereKeyNot($lineId))
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.office_id" => 'Each office and category pair may only appear once per schedule.',
                    ]);
                }
            }
        }
    }

    protected function parseDate(mixed $value): Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        return Carbon::parse($value)->startOfDay();
    }
}
