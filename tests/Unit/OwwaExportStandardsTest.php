<?php

namespace Tests\Unit;

use App\Support\OwwaExportStandards;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Tests\TestCase;

class OwwaExportStandardsTest extends TestCase
{
    public function test_ledger_rows_for_transaction_count_adds_five_blank_rows(): void
    {
        $this->assertSame(5, OwwaExportStandards::ledgerRowsForTransactionCount(0));
        $this->assertSame(7, OwwaExportStandards::ledgerRowsForTransactionCount(2));
        $this->assertSame(8, OwwaExportStandards::ledgerRowsForTransactionCount(3));
    }

    public function test_ledger_rows_for_transaction_count_honors_per_form_blank_rows(): void
    {
        $this->assertSame(9, OwwaExportStandards::ledgerRowsForTransactionCount(7, 2));
    }

    public function test_resolve_column_alignments_maps_semantic_types(): void
    {
        $alignments = OwwaExportStandards::resolveColumnAlignments([
            'A' => 'date',
            'B' => 'reference',
            'C' => 'qty',
            'D' => 'amount',
            'E' => 'balance',
        ]);

        $this->assertSame(Alignment::HORIZONTAL_CENTER, $alignments['A']);
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $alignments['B']);
        $this->assertSame(Alignment::HORIZONTAL_CENTER, $alignments['C']);
        $this->assertSame(Alignment::HORIZONTAL_RIGHT, $alignments['D']);
        $this->assertSame(Alignment::HORIZONTAL_RIGHT, $alignments['E']);
    }
}
