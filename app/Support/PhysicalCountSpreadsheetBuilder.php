<?php

namespace App\Support;

use App\Models\PhysicalCountSession;
use App\Services\OwwaItemReportService;
use App\Services\OwwaTemplateExportService;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PhysicalCountSpreadsheetBuilder
{
    public function __construct(
        protected OwwaTemplateExportService $exportService,
        protected OwwaItemReportService $reportService,
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
        PhysicalCountSession $session,
        string $formCode,
        string $templateFilename,
        ?int $sheetIndex = 0,
        ?string $sheetName = null,
        ?Collection $lines = null,
        ?string $propertyClass = null,
    ): Spreadsheet {
        PhpExtensionGuard::ensureZipArchive();

        $lineCollection = $lines ?? $session->lines;
        $absolutePath = $this->exportService->requireTemplateAbsolutePath($templateFilename);
        $spreadsheet = OwwaTemplateLoader::load($absolutePath);
        $masterSheet = filled($sheetName)
            ? ($spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getSheet($sheetIndex))
            : $spreadsheet->getSheet($sheetIndex);

        $useMasterSignatures = $formCode === 'RPCSP' && $sheetName === 'RPCSP';

        if (PhysicalCountPageLayout::isContinuousLayout($formCode)) {
            $this->exportService->populateContinuousPhysicalCountSheet(
                $masterSheet,
                $session,
                $formCode,
                $lineCollection,
                $propertyClass,
                $sheetName,
                $useMasterSignatures,
            );
        } else {
            $maxRows = PhysicalCountPageLayout::rowsPerPage($formCode);
            $chunks = self::chunkLines($lineCollection, $maxRows);

            $referenceSpreadsheet = OwwaTemplateLoader::load($absolutePath);
            $templateReference = filled($sheetName)
                ? ($referenceSpreadsheet->getSheetByName($sheetName) ?? $referenceSpreadsheet->getSheet($sheetIndex))
                : $referenceSpreadsheet->getSheet($sheetIndex);

            try {
                $this->exportService->populatePhysicalCountSheet(
                    $masterSheet,
                    $templateReference,
                    $session,
                    $formCode,
                    $chunks,
                    $propertyClass,
                    $sheetName,
                    $useMasterSignatures,
                );
            } finally {
                $referenceSpreadsheet->disconnectWorksheets();
            }
        }

        if (filled($sheetName)) {
            $masterSheet->setTitle($this->exportService->sanitizePhysicalCountSheetTitle($sheetName));
        } elseif ($formCode === 'RPCI' || $formCode === 'RPCPPE') {
            $masterSheet->setTitle($this->exportService->sanitizePhysicalCountSheetTitle($formCode));
        }

        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($masterSheet));

        return $spreadsheet;
    }

    public function populateStackedBlocksOnSheet(
        Worksheet $sheet,
        Worksheet $masterSheet,
        PhysicalCountSession $session,
        string $formCode,
        Collection $lines,
        ?string $propertyClass = null,
        ?string $sheetName = null,
        bool $useMasterSignatures = false,
    ): void {
        if (PhysicalCountPageLayout::isContinuousLayout($formCode)) {
            $this->exportService->populateContinuousPhysicalCountSheet(
                $sheet,
                $session,
                $formCode,
                $lines,
                $propertyClass,
                $sheetName,
                $useMasterSignatures,
            );

            return;
        }

        $chunks = self::chunkLines($lines, PhysicalCountPageLayout::rowsPerPage($formCode));

        $this->exportService->populatePhysicalCountSheet(
            $sheet,
            $masterSheet,
            $session,
            $formCode,
            $chunks,
            $propertyClass,
            $sheetName,
            $useMasterSignatures,
        );
    }
}
