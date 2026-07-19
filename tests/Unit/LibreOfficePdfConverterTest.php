<?php

namespace Tests\Unit;

use App\Services\LibreOfficePdfConverter;
use App\Services\OwwaTemplateExportService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class LibreOfficePdfConverterTest extends TestCase
{
    public function test_is_available_is_false_when_disabled(): void
    {
        config(['services.libreoffice.enabled' => false]);

        $this->assertFalse(app(LibreOfficePdfConverter::class)->isAvailable());
    }

    public function test_convert_returns_null_when_disabled(): void
    {
        config(['services.libreoffice.enabled' => false]);

        $this->assertNull(app(LibreOfficePdfConverter::class)->convertXlsxBinary('PK fake'));
    }

    public function test_convert_returns_null_when_process_fails(): void
    {
        config([
            'services.libreoffice.enabled' => true,
            'services.libreoffice.binary' => 'soffice',
            'services.libreoffice.timeout' => 30,
        ]);

        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
        ]);

        $this->assertNull(app(LibreOfficePdfConverter::class)->convertXlsxBinary('PK fake-xlsx'));
    }

    public function test_spreadsheet_to_pdf_falls_back_to_dompdf_when_libreoffice_fails(): void
    {
        config(['services.libreoffice.enabled' => false]);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'PO test');

        $pdf = app(OwwaTemplateExportService::class)->spreadsheetToPdfBinary($spreadsheet);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_convert_returns_pdf_when_libreoffice_succeeds(): void
    {
        config([
            'services.libreoffice.enabled' => true,
            'services.libreoffice.binary' => 'soffice',
            'services.libreoffice.timeout' => 30,
        ]);

        Process::fake(function (PendingProcess $process) {
            $command = implode(' ', $process->command);

            if (str_contains($command, '--version')) {
                return Process::result(output: 'LibreOffice 24.2', exitCode: 0);
            }

            $outdir = null;
            foreach ($process->command as $index => $part) {
                if ($part === '--outdir' && isset($process->command[$index + 1])) {
                    $outdir = $process->command[$index + 1];
                    break;
                }
            }

            if (is_string($outdir) && is_dir($outdir)) {
                file_put_contents($outdir.DIRECTORY_SEPARATOR.'export.pdf', "%PDF-1.4\nfake");
            }

            return Process::result(output: 'convert ok', exitCode: 0);
        });

        $pdf = app(LibreOfficePdfConverter::class)->convertXlsxBinary('PK fake-xlsx');

        $this->assertIsString($pdf);
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
