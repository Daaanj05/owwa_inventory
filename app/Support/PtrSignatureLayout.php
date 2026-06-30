<?php

namespace App\Support;

use App\Models\Transfer;

class PtrSignatureLayout
{
    /**
     * @return array<string, string|int|float|null>
     */
    public static function applyTransferTypeMarks(array $values, Transfer $transfer): array
    {
        $map = (array) (OwwaCellMapping::form('PTR')['transfer_type_marks'] ?? []);
        $transferType = $transfer->transfer_type;

        if ($transferType === 'donation' && isset($map['donation'])) {
            $values[$map['donation']] = 'X';
        } elseif ($transferType === 'relocate' && isset($map['relocate'])) {
            $values[$map['relocate']] = 'X';
        } elseif ($transferType === 'reassignment' && isset($map['reassignment'])) {
            $values[$map['reassignment']] = 'X';
        } elseif ($transferType === 'others' && isset($map['others'])) {
            $suffix = $transfer->transfer_type_other ? ' '.$transfer->transfer_type_other : '';
            $values[$map['others']] = 'X'.$suffix;
        }

        return $values;
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public static function applySignatureBlock(array $values, Transfer $transfer): array
    {
        $signatures = (array) (OwwaCellMapping::form('PTR')['signatures'] ?? []);
        $date = $transfer->transfer_date?->format('Y-m-d') ?? '';
        $from = $transfer->fromOffice;
        $to = $transfer->toOffice;

        $pairs = [
            'approved_name' => $transfer->approved_by_printed_name ?? $from?->name ?? '',
            'approved_designation' => $transfer->approved_by_designation ?? '',
            'released_name' => $transfer->released_by_printed_name ?? $transfer->recordedBy?->name ?? '',
            'released_designation' => $transfer->released_by_designation ?? '',
            'received_name' => $transfer->received_by_printed_name ?? $to?->name ?? '',
            'received_designation' => $transfer->received_by_designation ?? '',
            'approved_date' => $date,
            'released_date' => $date,
            'received_date' => $date,
        ];

        foreach ($pairs as $field => $value) {
            if (isset($signatures[$field])) {
                $values[$signatures[$field]] = $value;
            }
        }

        if (isset($signatures['reason'])) {
            $values[$signatures['reason']] = $transfer->reason_for_transfer ?? $transfer->remarks ?? '';
        }

        return $values;
    }
}
