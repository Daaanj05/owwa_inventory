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

    public function resolveOfficeName(): ?string
    {
        return $this->resolveOffice()?->name;
    }

    public function resolveOffice(): ?Office
    {
        $designated = Office::query()
            ->active()
            ->where('is_regional_supply', true)
            ->orderBy('name')
            ->first();

        if ($designated !== null) {
            return $designated;
        }

        $custodianOffice = $this->resolveSingleCustodianOffice();

        if ($custodianOffice !== null) {
            return $custodianOffice;
        }

        $regionalOffice = Office::query()
            ->active()
            ->where('is_satellite', false)
            ->orderBy('name')
            ->first();

        if ($regionalOffice !== null) {
            return $regionalOffice;
        }

        return null;
    }

    protected function resolveSingleCustodianOffice(): ?Office
    {
        $custodianOfficeIds = User::query()
            ->where('role', User::ROLE_SUPPLY_CUSTODIAN)
            ->whereNotNull('office_id')
            ->distinct()
            ->pluck('office_id');

        if ($custodianOfficeIds->count() !== 1) {
            return null;
        }

        return Office::query()
            ->active()
            ->find($custodianOfficeIds->first());
    }
}
