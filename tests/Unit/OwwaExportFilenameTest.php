<?php

namespace Tests\Unit;

use App\Models\PhysicalCountSession;
use App\Support\OwwaExportFilename;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OwwaExportFilenameTest extends TestCase
{
    public function test_transaction_builds_form_dash_reference_filename(): void
    {
        $this->assertSame(
            'RIS-2026-01-0400.xlsx',
            OwwaExportFilename::transaction('RIS', '2026-01-0400'),
        );
    }

    public function test_transaction_does_not_double_prefix_when_reference_already_prefixed(): void
    {
        $this->assertSame(
            'RIS-2026-01-0400.xlsx',
            OwwaExportFilename::transaction('RIS', 'RIS-2026-01-0400'),
        );
    }

    public function test_batch_builds_form_dash_batch_dash_timestamp_filename(): void
    {
        $filename = OwwaExportFilename::batch('RSMI', now()->parse('2026-06-04 14:30:22'));

        $this->assertSame('RSMI-batch-2026-06-04_143022.xlsx', $filename);
    }

    public function test_batch_supports_par_and_ics_form_codes(): void
    {
        $at = now()->parse('2026-06-04 14:30:22');

        $this->assertSame('PAR-batch-2026-06-04_143022.xlsx', OwwaExportFilename::batch('PAR', $at));
        $this->assertSame('ICS-batch-2026-06-04_143022.xlsx', OwwaExportFilename::batch('ICS', $at));
        $this->assertSame('PC-batch-2026-06-04_143022.xlsx', OwwaExportFilename::batch('PC', $at));
        $this->assertSame('AnnexA1-batch-2026-06-04_143022.xlsx', OwwaExportFilename::batch('AnnexA1', $at));
    }

    public function test_bulk_workbook_maps_category_specific_labels(): void
    {
        $at = now()->parse('2026-06-04 14:30:22');

        $this->assertSame('PAR-batch-2026-06-04_143022.xlsx', OwwaExportFilename::bulkWorkbook('par', $at));
        $this->assertSame('ICS-batch-2026-06-04_143022.xlsx', OwwaExportFilename::bulkWorkbook('ics', $at));
        $this->assertSame('PC-batch-2026-06-04_143022.xlsx', OwwaExportFilename::bulkWorkbook('pc', $at));
        $this->assertSame('AnnexA1-batch-2026-06-04_143022.xlsx', OwwaExportFilename::bulkWorkbook('annexa1', $at));
        $this->assertSame('Issuances-batch-2026-06-04_143022.xlsx', OwwaExportFilename::bulkWorkbook('issuances', $at));
    }

    public function test_bulk_workbook_maps_requisitions_label_to_ris(): void
    {
        $filename = OwwaExportFilename::bulkWorkbook('requisitions', now()->parse('2026-06-04 14:30:22'));

        $this->assertSame('RIS-batch-2026-06-04_143022.xlsx', $filename);
    }

    public function test_item_report_maps_form_slug_to_form_code(): void
    {
        $this->assertSame(
            'SC-CON-2026-0001.xlsx',
            OwwaExportFilename::itemReport('sc', 'CON-2026-0001'),
        );
        $this->assertSame(
            'AnnexA1-SE-2026-0042.xlsx',
            OwwaExportFilename::itemReport('annex_a1', 'SE-2026-0042'),
        );
    }

    #[DataProvider('physicalCountTypeProvider')]
    public function test_physical_count_uses_count_type_form_code(string $countType, string $expectedPrefix): void
    {
        $filename = OwwaExportFilename::physicalCount($countType, '2026-01-0099');

        $this->assertSame($expectedPrefix.'-2026-01-0099.xlsx', $filename);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function physicalCountTypeProvider(): array
    {
        return [
            'consumables' => [PhysicalCountSession::TYPE_RPCI, 'RPCI'],
            'ppe' => [PhysicalCountSession::TYPE_RPCPPE, 'RPCPPE'],
            'semi' => [PhysicalCountSession::TYPE_RPCSP, 'RPCSP'],
        ];
    }

    public function test_coa_report_supports_summary_single_and_batch_names(): void
    {
        $this->assertSame(
            'COA-StockLevel-2026-06-04.pdf',
            OwwaExportFilename::coaReport('StockLevel', '2026-06-04'),
        );
        $this->assertSame(
            'COA-Issuance-2026-01-0501.pdf',
            OwwaExportFilename::coaReport('Issuance', '2026-01-0501'),
        );
        $this->assertSame(
            'COA-Issuance-batch-2026-06-04_143022.pdf',
            OwwaExportFilename::coaReport('Issuance', '2026-06-04_143022', isBatch: true),
        );
    }

    public function test_qr_label_and_csv_export_patterns(): void
    {
        $this->assertSame(
            'QR-Acquisition-2026-01-0003.pdf',
            OwwaExportFilename::qrLabel('Acquisition', '2026-01-0003'),
        );
        $this->assertSame(
            'AtRiskProcurement-2026-06-04.csv',
            OwwaExportFilename::csvExport('AtRiskProcurement', now()->parse('2026-06-04')),
        );
    }

    public function test_sanitize_segment_replaces_unsafe_characters(): void
    {
        $this->assertSame(
            'SPHV_2026_ICT_106',
            OwwaExportFilename::sanitizeSegment('SPHV/2026 ICT*106'),
        );
    }
}
