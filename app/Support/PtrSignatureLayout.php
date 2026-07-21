<?php

namespace App\Support;

use App\Models\Transfer;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PtrSignatureLayout
{
    /**
     * @return array<string, string|int|float|null>
     */
    public static function applyTransferTypeMarks(array $values, Transfer $transfer): array
    {
        $map = (array) (OwwaCellMapping::form('PTR')['transfer_type_marks'] ?? []);
        $othersLabelCell = (string) (OwwaCellMapping::form('PTR')['transfer_type_others_label'] ?? 'F14');
        $transferType = strtolower(trim((string) ($transfer->transfer_type ?? '')));
        $othersSpecify = trim((string) ($transfer->transfer_type_other ?? ''));

        // Template only has Donation / Relocate / Reassignment / Others.
        if ($transferType === 'return') {
            $transferType = 'others';
            if ($othersSpecify === '') {
                $othersSpecify = 'Return to stock';
            }
        }

        if ($transferType === 'donation' && isset($map['donation'])) {
            $values[$map['donation']] = '✓';
        } elseif ($transferType === 'relocate' && isset($map['relocate'])) {
            $values[$map['relocate']] = '✓';
        } elseif ($transferType === 'reassignment' && isset($map['reassignment'])) {
            $values[$map['reassignment']] = '✓';
        } elseif ($transferType === 'others' && isset($map['others'])) {
            $values[$map['others']] = '✓';
            $values[$othersLabelCell] = $othersSpecify !== ''
                ? 'Others (Specify) '.$othersSpecify
                : 'Others (Specify) _________________';
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

    /**
     * Prevent printed-name clipping on PTR signature row 53 (and designations on 54).
     */
    public static function finalizePrintedNameLayout(Worksheet $sheet): void
    {
        $signatures = (array) (OwwaCellMapping::form('PTR')['signatures'] ?? []);
        $nameCells = array_values(array_filter([
            $signatures['approved_name'] ?? null,
            $signatures['released_name'] ?? null,
            $signatures['received_name'] ?? null,
        ], fn (mixed $cell): bool => filled($cell)));

        $designationCells = array_values(array_filter([
            $signatures['approved_designation'] ?? null,
            $signatures['released_designation'] ?? null,
            $signatures['received_designation'] ?? null,
        ], fn (mixed $cell): bool => filled($cell)));

        foreach (array_merge($nameCells, $designationCells) as $cellRef) {
            $style = $sheet->getStyle((string) $cellRef);
            $style->getAlignment()->setWrapText(true);
            $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }

        self::expandRowForWrappedCells($sheet, 53, ['B', 'F', 'H'], 20.0);
        self::expandRowForWrappedCells($sheet, 54, ['B', 'F', 'H'], 18.0);
    }

    /**
     * @param  list<string>  $wrapColumns
     */
    protected static function expandRowForWrappedCells(
        Worksheet $sheet,
        int $row,
        array $wrapColumns,
        float $minimumHeight = 18.0,
    ): void {
        $baseHeight = (float) ($sheet->getRowDimension($row)->getRowHeight() ?: 13.8);
        $estimated = OwwaSpreadsheetLayoutHelper::estimateWrappedRowHeight(
            $sheet,
            $row,
            $wrapColumns,
            max($baseHeight, $minimumHeight),
            2,
        );

        $sheet->getRowDimension($row)->setRowHeight(min(max($minimumHeight, $estimated), 48.0));
    }
}
