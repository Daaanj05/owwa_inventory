<?php

namespace App\Support;

use App\Models\Requisition;

class RequisitionStatus
{
    public static function label(?string $status): string
    {
        return match ($status) {
            Requisition::STATUS_PENDING => 'Pending',
            Requisition::STATUS_ACCEPTED => 'Accepted',
            Requisition::STATUS_REJECTED => 'Rejected',
            default => ucfirst($status ?? 'pending'),
        };
    }

    public static function color(?string $status): string
    {
        return match ($status) {
            Requisition::STATUS_PENDING => 'warning',
            Requisition::STATUS_ACCEPTED => 'success',
            Requisition::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        return [
            Requisition::STATUS_PENDING => self::label(Requisition::STATUS_PENDING),
            Requisition::STATUS_ACCEPTED => self::label(Requisition::STATUS_ACCEPTED),
            Requisition::STATUS_REJECTED => self::label(Requisition::STATUS_REJECTED),
        ];
    }
}
