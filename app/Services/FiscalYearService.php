<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\Department;
use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class FiscalYearService
{
    protected const SESSION_KEY = 'active_fiscal_year_id';

    public function bypass(): bool
    {
        return (bool) config('app.fiscal_year_bypass', false);
    }

    public function current(): ?FiscalYear
    {
        if ($this->bypass()) {
            return null;
        }

        $id = Session::get(self::SESSION_KEY);
        if ($id) {
            return FiscalYear::find($id);
        }

        // If there is exactly one fiscal year configured, auto-select it.
        $count = FiscalYear::count();
        if ($count === 1) {
            $year = FiscalYear::query()->first();
            if ($year) {
                Session::put(self::SESSION_KEY, $year->id);
            }

            return $year;
        }

        // Otherwise, require the user to pick a fiscal year via the UI.
        return null;
    }

    public function setCurrent(?int $id): void
    {
        if ($id === null) {
            Session::forget(self::SESSION_KEY);
            return;
        }

        if (FiscalYear::whereKey($id)->exists()) {
            Session::put(self::SESSION_KEY, $id);
        }

        $this->syncAuthenticatedUserSetupToFiscalYear($id);
    }

    /**
     * If the app uses fiscal-year-scoped setup tables (offices/departments),
     * keep the authenticated user's office/department pointing at the chosen year's records.
     */
    protected function syncAuthenticatedUserSetupToFiscalYear(?int $fiscalYearId): void
    {
        if ($this->bypass() || $fiscalYearId === null) {
            return;
        }

        $user = Auth::user();
        if (! $user) {
            return;
        }

        $currentOffice = $user->office_id ? Office::find($user->office_id) : null;
        if (! $currentOffice) {
            return;
        }

        $newOfficeId = Office::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('code', $currentOffice->code)
            ->value('id');

        if (! $newOfficeId) {
            return;
        }

        $updates = [];
        if ((int) $user->office_id !== (int) $newOfficeId) {
            $updates['office_id'] = (int) $newOfficeId;
        }

        if ($user->department_id) {
            $currentDepartment = Department::find($user->department_id);
            if ($currentDepartment) {
                $departmentQuery = Department::query()
                    ->where('fiscal_year_id', $fiscalYearId)
                    ->where('office_id', $newOfficeId);

                if ($currentDepartment->code) {
                    $departmentQuery->where('code', $currentDepartment->code);
                } else {
                    $departmentQuery->where('name', $currentDepartment->name);
                }

                $newDepartmentId = $departmentQuery->value('id');
                if ($newDepartmentId) {
                    $updates['department_id'] = (int) $newDepartmentId;
                }
            }
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }
    }

    /**
     * Get the active fiscal year date range, or null if none.
     *
     * @return array{from: Carbon, to: Carbon}|null
     */
    public function range(): ?array
    {
        if ($this->bypass()) {
            return null;
        }

        $year = $this->current();
        if (! $year) {
            return null;
        }

        return [
            'from' => $year->start_date->copy(),
            'to'   => $year->end_date->copy(),
        ];
    }

    /**
     * Validate that a given date string falls inside the active fiscal year.
     * Returns null if valid, or an error message string if invalid.
     */
    public function validateDateInCurrentYear(?string $date): ?string
    {
        if ($this->bypass() || ! $date) {
            return null;
        }

        $range = $this->range();
        if (! $range) {
            return null;
        }

        $d = Carbon::parse($date);
        if (! $d->between($range['from'], $range['to'])) {
            return 'Date must be within the selected fiscal year.';
        }

        return null;
    }

    /**
     * Apply filter so the query only returns records whose date column is within the current fiscal year.
     * No-op if bypass is on or no fiscal year is selected.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function applyDateRangeFilter($query, string $dateColumn): mixed
    {
        $range = $this->range();
        if ($range === null) {
            return $query;
        }

        return $query->whereBetween($dateColumn, [$range['from'], $range['to']]);
    }
}

