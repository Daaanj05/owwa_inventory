<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class AnalyzeOwwaTemplatesCommand extends Command
{
    protected $signature = 'owwa:analyze-templates
                            {--output= : Path to write report (default: storage/app/templates/template-structure.txt)}';

    protected $description = 'Analyze OWWA Excel templates and output cell structure for DB/form mapping';

    public function handle(): int
    {
        $templatesPath = storage_path('app/templates');
        if (! is_dir($templatesPath)) {
            $this->error('Templates directory not found: ' . $templatesPath);

            return self::FAILURE;
        }

        $excelFiles = $this->findExcelFiles($templatesPath);
        if ($excelFiles === []) {
            $this->warn('No .xlsx or .xls files found in ' . $templatesPath);
            $this->line('Place OWWA form files in storage/app/templates/ (e.g. consumables/, ppe/, semi_expendable/).');

            return self::SUCCESS;
        }

        $report = $this->buildReport($templatesPath, $excelFiles);
        $this->line($report);

        $outputPath = $this->option('output') ?? $templatesPath . DIRECTORY_SEPARATOR . 'template-structure.txt';
        if (is_string($outputPath)) {
            file_put_contents($outputPath, $report);
            $this->info('Report written to: ' . $outputPath);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function findExcelFiles(string $basePath): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if ($ext === 'xlsx' || $ext === 'xls') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    protected function buildReport(string $templatesPath, array $excelPaths): string
    {
        $lines = [];
        $lines[] = 'OWWA template structure report – ' . now()->toDateTimeString();
        $lines[] = 'Use this to map database columns and export cell references.';
        $lines[] = str_repeat('=', 72);
        $lines[] = '';

        foreach ($excelPaths as $fullPath) {
            $relativePath = str_replace($templatesPath . DIRECTORY_SEPARATOR, '', $fullPath);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            $lines[] = 'FILE: ' . $relativePath;
            $lines[] = str_repeat('-', 72);

            try {
                $spreadsheet = IOFactory::load($fullPath);
                $sheetCount = $spreadsheet->getSheetCount();
                for ($i = 0; $i < $sheetCount; $i++) {
                    $sheet = $spreadsheet->getSheet($i);
                    $sheetName = $sheet->getTitle();
                    $lines[] = '  Sheet ' . ($i + 1) . ': "' . $sheetName . '"';
                    $maxRow = (int) $sheet->getHighestRow();
                    $maxCol = $sheet->getHighestColumn();
                    $maxColIndex = $maxRow > 0 ? Coordinate::columnIndexFromString($maxCol) : 0;
                    $maxRow = min($maxRow, 200);
                    $maxColIndex = min($maxColIndex, 40);
                    for ($row = 1; $row <= $maxRow; $row++) {
                        for ($col = 1; $col <= $maxColIndex; $col++) {
                            $coord = Coordinate::stringFromColumnIndex($col) . $row;
                            $val = $sheet->getCell($coord)->getValue();
                            if ($val !== null && (string) $val !== '') {
                                $lines[] = '    ' . $coord . ': ' . $this->summarizeValue($val);
                            }
                        }
                    }
                    $lines[] = '';
                }
            } catch (\Throwable $e) {
                $lines[] = '  ERROR: ' . $e->getMessage();
                $lines[] = '';
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    protected function summarizeValue(mixed $value): string
    {
        if (is_float($value) || is_int($value)) {
            return (string) $value;
        }
        $s = (string) $value;
        if (strlen($s) > 80) {
            return substr($s, 0, 77) . '...';
        }

        return '"' . str_replace(["\r", "\n"], ' ', $s) . '"';
    }
}
