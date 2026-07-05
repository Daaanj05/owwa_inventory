<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProcurementHeaderLayout
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function splitOfficeSection(string $name, int $maxFirstLineChars = 28): array
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';

        if ($name === '' || mb_strlen($name) <= $maxFirstLineChars) {
            return [$name, ''];
        }

        $slice = mb_substr($name, 0, $maxFirstLineChars);
        $lastSpace = mb_strrpos($slice, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            $first = mb_substr($name, 0, $lastSpace);
            $rest = ltrim(mb_substr($name, $lastSpace));

            return [$first, $rest];
        }

        return [
            mb_substr($name, 0, $maxFirstLineChars),
            ltrim(mb_substr($name, $maxFirstLineChars)),
        ];
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     * @param  array<string, mixed>  $headerMap
     */
    public static function applyOfficeSectionHeader(array &$values, array $headerMap, string $officeName): void
    {
        $spec = (array) ($headerMap['office_section'] ?? []);
        $cell = (string) ($spec['cell'] ?? 'A7');
        $label = (string) ($spec['label'] ?? 'Office/Section : ');
        $maxChars = (int) ($spec['max_first_line_chars'] ?? 28);
        $continuationCell = (string) ($spec['continuation_cell'] ?? '');

        [$first, $rest] = self::splitOfficeSection($officeName, $maxChars);
        $values[$cell] = $label.$first;

        if ($rest !== '' && $continuationCell !== '') {
            $values[$continuationCell] = $rest;
        }
    }

    /**
     * @param  array<string, mixed>  $officeSectionSpec
     */
    public static function finalizeOfficeSectionRows(Worksheet $sheet, array $officeSectionSpec): void
    {
        $wrapColumn = (string) ($officeSectionSpec['wrap_column'] ?? 'A');
        $wrapRows = (array) ($officeSectionSpec['wrap_rows'] ?? []);

        if ($wrapRows === []) {
            foreach (['cell', 'continuation_cell'] as $key) {
                $cellRef = (string) ($officeSectionSpec[$key] ?? '');

                if ($cellRef !== '') {
                    $wrapRows[] = (int) preg_replace('/\D+/', '', $cellRef);
                }
            }
        }

        $wrapRows = array_values(array_filter($wrapRows, fn (int $row): bool => $row > 0));

        if ($wrapRows === []) {
            return;
        }

        $standardHeight = OwwaSpreadsheetLayoutHelper::resolveLedgerRowHeight($sheet, $wrapRows[0]);

        foreach ($wrapRows as $row) {
            $coordinate = $wrapColumn.$row;
            $alignment = $sheet->getStyle($coordinate)->getAlignment();
            $alignment->setWrapText(true);
            $alignment->setVertical(Alignment::VERTICAL_TOP);

            $estimatedHeight = OwwaSpreadsheetLayoutHelper::estimateWrappedRowHeight(
                $sheet,
                $row,
                [$wrapColumn],
                $standardHeight,
                2,
            );

            if ($estimatedHeight > $standardHeight) {
                $sheet->getRowDimension($row)->setRowHeight($estimatedHeight);
            }
        }
    }
}
