<?php

namespace App\Support;

use App\Models\AcquisitionPaperwork;
use App\Services\OwwaTemplateExportService;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProcurementSpreadsheetBuilder
{
    public function __construct(
        protected OwwaTemplateExportService $exportService,
    ) {}

    /**
     * @return array<int, Collection<int, mixed>>
     */
    public static function chunkLines(Collection $lines, int $maxRows): array
    {
        if ($lines->isEmpty()) {
            return [collect()];
        }

        return $lines->values()->chunk($maxRows)->all();
    }

    public function build(
        AcquisitionPaperwork $paperwork,
        string $formSlug,
        string $templateFilename,
    ): Spreadsheet {
        PhpExtensionGuard::ensureZipArchive();

        $formCode = match ($formSlug) {
            'po' => 'PO',
            'iar' => 'IAR',
            default => 'PR',
        };

        $detail = (array) OwwaCellMapping::form($formCode)['detail'];
        $maxRows = (int) ($detail['max_rows'] ?? 20);
        $chunks = self::chunkLines($paperwork->lines, $maxRows);
        $pageCount = count($chunks);

        $absolutePath = $this->exportService->requireTemplateAbsolutePath($templateFilename);
        $spreadsheet = OwwaTemplateLoader::load($absolutePath);
        $masterSheet = $spreadsheet->getSheet(0);

        if ($pageCount === 1) {
            $cellValues = $this->exportService->buildProcurementSheetCellValues(
                $paperwork,
                $formSlug,
                $chunks[0],
                true,
                0,
                1,
            );
            $this->exportService->applyFilledProcurementSheet($masterSheet, $templateFilename, $cellValues);
        } else {
            $templateSheet = $this->cloneProcurementWorksheet($spreadsheet, $masterSheet);
            $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($masterSheet));

            foreach ($chunks as $pageIndex => $chunkLines) {
                $isLastPage = $pageIndex === ($pageCount - 1);
                $sheet = $pageIndex === 0 ? $templateSheet : $this->cloneProcurementWorksheet($spreadsheet, $templateSheet);
                $sheet->setTitle($this->exportService->procurementSheetTitle($formCode, $pageIndex, $pageCount));
                $spreadsheet->addSheet($sheet);

                $cellValues = $this->exportService->buildProcurementSheetCellValues(
                    $paperwork,
                    $formSlug,
                    $chunkLines,
                    $isLastPage,
                    $pageIndex,
                    $pageCount,
                );

                $this->exportService->applyFilledProcurementSheet($sheet, $templateFilename, $cellValues);

                if (! $isLastPage) {
                    $this->exportService->clearProcurementFooterValues($sheet, $formCode);

                    if ($formCode === 'IAR') {
                        OwwaSpreadsheetLayoutHelper::ensureIarAcceptanceCheckboxes($sheet);
                    }
                }
            }

            $spreadsheet->setActiveSheetIndex(0);
        }

        if ($formCode === 'PO') {
            $poData = (array) ($paperwork->po_data ?? []);
            $specs = $paperwork->purchaseOrder?->technical_specifications
                ?? ($poData['technical_specifications'] ?? null);
            PurchaseOrderTechnicalSpecificationSheet::append(
                $spreadsheet,
                is_string($specs) ? $specs : null,
                $paperwork->po_number ?? $paperwork->purchaseOrder?->number,
            );
        }

        return $spreadsheet;
    }

    protected function cloneProcurementWorksheet(Spreadsheet $spreadsheet, Worksheet $masterSheet): Worksheet
    {
        try {
            return clone $masterSheet;
        } catch (\Throwable) {
            $cloned = new Worksheet($spreadsheet, 'ProcurementClone');
            OwwaSpreadsheetLayoutHelper::copyWorksheetRows(
                $masterSheet,
                $cloned,
                1,
                1,
                min($masterSheet->getHighestRow(), 50),
                'F',
            );

            return $cloned;
        }
    }
}
