<?php

namespace App\Rules;

use App\Models\Department;
use App\Services\FiscalYearService;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueDepartmentNameInOffice implements DataAwareRule, ValidationRule
{
    protected array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $officeId = $this->data['office_id'] ?? null;
        if (! $officeId) {
            return;
        }

        $fiscalYearId = app(FiscalYearService::class)->current()?->id;
        if (! $fiscalYearId) {
            return;
        }

        $query = Department::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('office_id', $officeId)
            ->where('name', $value);

        if (isset($this->data['id'])) {
            $query->where('id', '!=', $this->data['id']);
        }

        if ($query->exists()) {
            $fail('A department with this name already exists in this office.');
        }
    }
}
