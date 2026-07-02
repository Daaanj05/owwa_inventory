<?php

namespace App\Support;

class AnnexA4Layout
{
    public static function templateSheetName(): string
    {
        return (string) (OwwaCellMapping::form('ANNEX_A4')['template_sheet'] ?? 'RegSPI');
    }

    public static function ledgerStartRow(): int
    {
        return (int) (OwwaCellMapping::form('ANNEX_A4')['ledger']['start_row'] ?? 12);
    }

    public static function ledgerStyleRow(): int
    {
        return (int) (OwwaCellMapping::form('ANNEX_A4')['ledger']['style_row'] ?? self::ledgerStartRow());
    }

    public static function headerRowCount(): int
    {
        return self::ledgerStartRow() - 1;
    }

    public static function highestColumn(): string
    {
        return (string) (OwwaCellMapping::form('ANNEX_A4')['ledger']['highest_column'] ?? 'O');
    }
}
