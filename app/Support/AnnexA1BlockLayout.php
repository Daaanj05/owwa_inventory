<?php

namespace App\Support;

/**
 * Annex A.1 property cards repeat the same block down a sheet tab (18-row stride).
 * The on-disk template is a single master sheet (SPC); export clones it per property class.
 */
class AnnexA1BlockLayout
{
    public const FIRST_ENTITY_ROW = 8;

    public const LEDGER_OFFSET_FROM_ENTITY = 7;

    public static function blockStride(): int
    {
        return (int) (OwwaCellMapping::form('ANNEX_A1')['block_stride'] ?? 18);
    }

    public static function templateSheetName(): string
    {
        return (string) (OwwaCellMapping::form('ANNEX_A1')['template_sheet'] ?? 'SPC');
    }

    public static function entityRow(int $blockIndex): int
    {
        return self::FIRST_ENTITY_ROW + ($blockIndex * self::blockStride());
    }

    public static function ledgerStartRow(int $blockIndex): int
    {
        return self::entityRow($blockIndex) + self::LEDGER_OFFSET_FROM_ENTITY;
    }

    public static function headerCell(string $field, int $blockIndex): string
    {
        $entityRow = self::entityRow($blockIndex);

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
    public static function applyHeader(array &$values, array $data, int $blockIndex): void
    {
        $headerMap = (array) (OwwaCellMapping::form('ANNEX_A1')['header'] ?? []);

        foreach ($headerMap as $field => $spec) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $label = (string) ($spec['label'] ?? '');
            $raw = $data[$field];
            $values[self::headerCell($field, $blockIndex)] = $label.($raw ?? '');
        }
    }

    public static function clearToRow(): int
    {
        return (int) (OwwaCellMapping::form('ANNEX_A1')['ledger']['clear_to_row'] ?? 500);
    }

    public static function maxBlocks(): int
    {
        $maxRow = self::clearToRow();
        $blockIndex = 0;

        while (self::entityRow($blockIndex) <= $maxRow) {
            $blockIndex++;
        }

        return max(1, $blockIndex);
    }
}
