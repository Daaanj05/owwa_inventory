<?php

namespace App\Support;

/**
 * Annex A.1 property cards stack full template blocks (OWWA header + item header + ledger) per item.
 */
class AnnexA1BlockLayout
{
    public const FIRST_BLOCK_START_ROW = 1;

    public static function owwaHeaderRows(): int
    {
        return (int) (OwwaCellMapping::form('ANNEX_A1')['owwa_header_rows'] ?? 7);
    }

    public static function itemHeaderRows(): int
    {
        return (int) (OwwaCellMapping::form('ANNEX_A1')['item_header_rows'] ?? 5);
    }

    public static function tableHeaderRows(): int
    {
        return (int) (OwwaCellMapping::form('ANNEX_A1')['table_header_rows'] ?? 2);
    }

    public static function headerSectionRows(): int
    {
        return self::owwaHeaderRows() + self::itemHeaderRows() + self::tableHeaderRows();
    }

    public static function ledgerStyleRow(): int
    {
        return (int) (OwwaCellMapping::form('ANNEX_A1')['ledger']['style_row'] ?? 15);
    }

    public static function ledgerDateFontSize(): float
    {
        return (float) (OwwaCellMapping::form('ANNEX_A1')['ledger']['date_font_size'] ?? 10);
    }

    /**
     * @return array<int, string>
     */
    public static function ledgerDateColumns(): array
    {
        $dateColumn = (string) (OwwaCellMapping::form('ANNEX_A1')['ledger']['columns']['date'] ?? 'A');

        return [$dateColumn];
    }

    public static function blankStyleRows(): int
    {
        return (int) (OwwaCellMapping::form('ANNEX_A1')['ledger']['blank_style_rows']
            ?? OwwaExportStandards::blankRowsAfterTransactions());
    }

    /** @deprecated Use blankStyleRows() */
    public static function minLedgerRows(): int
    {
        return self::blankStyleRows();
    }

    public static function ledgerRowsForTransactionCount(int $transactionCount): int
    {
        return OwwaExportStandards::ledgerRowsForTransactionCount($transactionCount);
    }

    public static function spacerRows(): int
    {
        return (int) (OwwaCellMapping::form('ANNEX_A1')['spacer_rows'] ?? 1);
    }

    public static function templateSheetName(): string
    {
        return (string) (OwwaCellMapping::form('ANNEX_A1')['template_sheet'] ?? 'SPC');
    }

    public static function masterTemplateBlockRows(): int
    {
        return self::headerSectionRows() + self::blankStyleRows();
    }

    public static function blockHeight(int $transactionCount): int
    {
        return self::headerSectionRows() + self::ledgerRowsForTransactionCount($transactionCount) + self::spacerRows();
    }

    /**
     * @param  array<int, int>  $transactionCounts
     * @return array<int, int>
     */
    public static function blockStartRows(array $transactionCounts): array
    {
        $starts = [];
        $cursor = self::FIRST_BLOCK_START_ROW;

        foreach ($transactionCounts as $count) {
            $starts[] = $cursor;
            $cursor += self::blockHeight($count);
        }

        return $starts;
    }

    public static function entityRowForBlockStart(int $blockStartRow): int
    {
        return $blockStartRow + self::owwaHeaderRows();
    }

    public static function ledgerStartRowForBlockStart(int $blockStartRow): int
    {
        return $blockStartRow + self::headerSectionRows();
    }

    public static function headerCell(string $field, int $blockStartRow): string
    {
        $entityRow = self::entityRowForBlockStart($blockStartRow);

        [$column, $rowOffset] = match ($field) {
            'entity_name' => ['A', 0],
            'fund_cluster' => ['K', 0],
            'property_type' => ['A', 2],
            'property_number' => ['K', 3],
            'description' => ['A', 4],
            default => throw new \InvalidArgumentException("Unknown Annex A.1 header field [{$field}]."),
        };

        return OwwaCellMapping::columnCell($column, $entityRow + $rowOffset);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string|null>  $data
     */
    public static function applyHeader(array &$values, array $data, int $blockStartRow): void
    {
        $headerMap = (array) (OwwaCellMapping::form('ANNEX_A1')['header'] ?? []);

        foreach ($headerMap as $field => $spec) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            if ($field === 'fund_cluster') {
                $values[self::headerCell($field, $blockStartRow)] = '';

                continue;
            }

            $label = (string) ($spec['label'] ?? '');
            $raw = $data[$field];
            $values[self::headerCell($field, $blockStartRow)] = $label.($raw ?? '');
        }
    }

    /**
     * @return array<string, string>
     */
    public static function ledgerColumnAlignments(): array
    {
        return (array) (OwwaCellMapping::form('ANNEX_A1')['ledger']['alignments'] ?? []);
    }

    /** @deprecated Use ledgerStartRowForBlockStart() */
    public static function ledgerStartRow(int $blockIndex): int
    {
        $starts = self::blockStartRows(array_fill(0, $blockIndex + 1, 0));

        return self::ledgerStartRowForBlockStart($starts[$blockIndex]);
    }

    /** @deprecated Use entityRowForBlockStart() */
    public static function entityRow(int $blockIndex): int
    {
        $starts = self::blockStartRows(array_fill(0, $blockIndex + 1, 0));

        return self::entityRowForBlockStart($starts[$blockIndex]);
    }

    /** @deprecated Fixed stride replaced by dynamic block heights */
    public static function blockStride(): int
    {
        return self::blockHeight(0);
    }

    public static function clearToRow(): int
    {
        return (int) (OwwaCellMapping::form('ANNEX_A1')['ledger']['clear_to_row'] ?? 500);
    }
}
