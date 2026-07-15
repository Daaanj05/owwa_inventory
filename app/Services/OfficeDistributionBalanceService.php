<?php

namespace App\Services;

use App\Models\Distribution;
use App\Models\Issuance;

class OfficeDistributionBalanceService
{
    public function issuedQuantity(int $itemId, int $officeId): int
    {
        if ($itemId <= 0 || $officeId <= 0) {
            return 0;
        }

        return (int) Issuance::query()
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->sum('quantity');
    }

    public function distributedQuantity(int $itemId, int $officeId): int
    {
        if ($itemId <= 0 || $officeId <= 0) {
            return 0;
        }

        return (int) Distribution::query()
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->sum('quantity');
    }

    public function availableQuantity(int $itemId, int $officeId): int
    {
        return max(0, $this->issuedQuantity($itemId, $officeId) - $this->distributedQuantity($itemId, $officeId));
    }
}
