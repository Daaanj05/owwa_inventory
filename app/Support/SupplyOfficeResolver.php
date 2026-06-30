<?php

namespace App\Support;

use App\Models\Office;
use App\Models\User;

class SupplyOfficeResolver
{
    public function resolve(): ?int
    {
        $office = $this->resolveOffice();

        return $office?->id;
    }

    public function resolveOffice(): ?Office
    {
        $regionalOffice = Office::query()
            ->active()
            ->where('is_satellite', false)
            ->orderBy('name')
            ->first();

        if ($regionalOffice !== null) {
            return $regionalOffice;
        }

        $custodianOfficeIds = User::query()
            ->where('role', User::ROLE_SUPPLY_CUSTODIAN)
            ->whereNotNull('office_id')
            ->distinct()
            ->pluck('office_id');

        if ($custodianOfficeIds->count() === 1) {
            return Office::query()->find($custodianOfficeIds->first());
        }

        return null;
    }
}
