<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\Disposal;
use App\Models\Issuance;
use App\Models\Requisition;
use App\Models\Transfer;
use App\Support\AnnexA1BlockLayout;
use App\Support\DisposalExportLayout;
use App\Support\OwwaCellMapping;
use App\Support\OwwaExportFilename;
use App\Support\PhpExtensionGuard;
use App\Support\PropertyCardLayout;
use App\Support\PtrSignatureLayout;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OwwaTemplateExportService
{
    protected string $templatesPath;

    public function __construct()
    {
        $this->templatesPath = storage_path('app/templates');
    }

    /**
     * Load an OWWA Excel template, fill cells with the given values, and return a download response.
     *
     * @param  array<string, string|int|float|null>  $cellValues  Map of cell references to values (e.g. ['B2' => 'ISS-001', 'C5' => 10])
     * @param  int  $sheetIndex  Zero-based sheet index when template has multiple sheets
     */
    public function downloadFromTemplate(
        string $templateFilename,
        array $cellValues,
        string $outputFilename,
        int $sheetIndex = 0,
        ?string $sheetName = null
    ): StreamedResponse {
        $binary = $this->renderTemplateToXlsxBinary($templateFilename, $cellValues, $sheetIndex, $sheetName);

        return response()->streamDownload(
            static function () use ($binary): void {
                echo $binary;
            },
            $outputFilename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
        );
    }

    /**
     * Resolve template file on disk (supports .xlsx with .xls fallback). Null if missing.
     */
    protected function tryResolveTemplateAbsolutePath(string $templateFilename): ?string
    {
        $path = $this->templatesPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $templateFilename);
        if (is_readable($path)) {
            return $path;
        }

        $altPath = preg_replace('/\.xlsx$/i', '.xls', $path);
        if ($altPath !== $path && is_readable($altPath)) {
            return $altPath;
        }

        return null;
    }

    /**
     * Load template (or plain workbook), apply cell values, return an in-memory spreadsheet.
     * Used for merging multiple exports without re-reading .xlsx (avoids ext-zip / ZipArchive on generated files).
     *
     * @param  array<string, string|int|float|null>  $cellValues
     */
    public function renderFilledSpreadsheet(string $templateFilename, array $cellValues, int $sheetIndex = 0, ?string $sheetName = null): Spreadsheet
    {
        $absolutePath = $this->tryResolveTemplateAbsolutePath($templateFilename);

        if ($absolutePath !== null) {
            if (str_ends_with(strtolower($absolutePath), '.xlsx')) {
                PhpExtensionGuard::ensureZipArchive();
            }

            $spreadsheet = IOFactory::load($absolutePath);
            $sheet = filled($sheetName)
                ? ($spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getSheet($sheetIndex))
                : $spreadsheet->getSheet($sheetIndex);

            if ($this->isAnnexA1PropertyCardTemplate($templateFilename)) {
                $this->clearAnnexA1SampleData($sheet);
            }
        } else {
            Log::warning('OWWA Excel template missing; generated plain spreadsheet instead.', [
                'expected_relative' => $templateFilename,
                'expected_absolute' => $this->templatesPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $templateFilename),
            ]);

            $spreadsheet = new Spreadsheet;
            $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Export');
        }

        foreach ($cellValues as $cellRef => $value) {
            $this->setExportCellValue($sheet, $cellRef, $value);
        }

        return $spreadsheet;
    }

    protected function setExportCellValue(Worksheet $sheet, string $cellRef, mixed $value): void
    {
        $resolvedRef = $this->resolveMergeAnchorCell($sheet, $cellRef);
        $sheet->setCellValue($resolvedRef, $value === null ? '' : $value);
    }

    protected function resolveMergeAnchorCell(Worksheet $sheet, string $cellRef): string
    {
        $cell = $sheet->getCell($cellRef);

        foreach ($sheet->getMergeCells() as $mergeRange) {
            if ($cell->isInRange($mergeRange)) {
                return explode(':', $mergeRange)[0];
            }
        }

        return $cellRef;
    }

    /**
     * @throws \RuntimeException
     */
    protected function requireTemplatePath(string $templateFilename): string
    {
        $absolutePath = $this->tryResolveTemplateAbsolutePath($templateFilename);

        if ($absolutePath === null) {
            throw new \RuntimeException(
                'OWWA template not found: '.$templateFilename.'. Place the file under storage/app/templates/ or contact your system administrator.'
            );
        }

        return $absolutePath;
    }

    /**
     * Serialize a spreadsheet to .xlsx bytes (writer uses ZipStream; reading those bytes back requires ext-zip).
     */
    public function spreadsheetToXlsxBinary(Spreadsheet $spreadsheet): string
    {
        PhpExtensionGuard::ensureZipArchive();

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        ob_start();
        $writer->save('php://output');

        return ob_get_clean() ?: '';
    }

    /**
     * Load template, apply cell values, serialize to .xlsx bytes.
     * If the OWWA file is not in storage, builds a plain workbook with the same cell values so exports still succeed.
     *
     * @param  array<string, string|int|float|null>  $cellValues
     */
    public function renderTemplateToXlsxBinary(string $templateFilename, array $cellValues, int $sheetIndex = 0, ?string $sheetName = null): string
    {
        $spreadsheet = $this->renderFilledSpreadsheet($templateFilename, $cellValues, $sheetIndex, $sheetName);

        try {
            return $this->spreadsheetToXlsxBinary($spreadsheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param  array<int, array{sheetName: string, cellValues: array<string, string|int|float|null>}>  $tabs
     */
    public function buildAnnexA1Spreadsheet(array $tabs, ?string $templateFilename = null): Spreadsheet
    {
        $templateFilename ??= (string) OwwaCellMapping::form('ANNEX_A1')['template'];
        $masterSheetName = AnnexA1BlockLayout::templateSheetName();

        PhpExtensionGuard::ensureZipArchive();

        $absolutePath = $this->tryResolveTemplateAbsolutePath($templateFilename);
        if ($absolutePath === null) {
            throw new \RuntimeException('Annex A.1 template not found: '.$templateFilename);
        }

        $spreadsheet = IOFactory::load($absolutePath);
        $masterSheet = $spreadsheet->getSheetByName($masterSheetName) ?? $spreadsheet->getSheet(0);
        $masterIndex = $spreadsheet->getIndex($masterSheet);

        if ($tabs === []) {
            $this->clearAnnexA1SampleData($masterSheet);
            $masterSheet->setTitle($this->sanitizeExcelSheetTitle('Export'));

            return $spreadsheet;
        }

        foreach ($tabs as $tab) {
            $cloned = clone $masterSheet;
            $cloned->setTitle($this->sanitizeExcelSheetTitle($tab['sheetName']));
            $this->clearAnnexA1SampleData($cloned);
            $this->applyCellValues($cloned, $tab['cellValues']);
            $spreadsheet->addSheet($cloned);
        }

        $spreadsheet->removeSheetByIndex($masterIndex);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  array<int, array{sheetName: string, cellValues: array<string, string|int|float|null>}>  $tabs
     */
    public function downloadAnnexA1Spreadsheet(array $tabs, string $outputFilename, ?string $templateFilename = null): StreamedResponse
    {
        $spreadsheet = $this->buildAnnexA1Spreadsheet($tabs, $templateFilename);

        try {
            $binary = $this->spreadsheetToXlsxBinary($spreadsheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return response()->streamDownload(
            static function () use ($binary): void {
                echo $binary;
            },
            $outputFilename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
        );
    }

    public function annexA1TemplatePath(): string
    {
        return (string) OwwaCellMapping::form('ANNEX_A1')['template'];
    }

    /**
     * @param  array<string, string|int|float|null>  $cellValues
     */
    protected function applyCellValues(Worksheet $sheet, array $cellValues): void
    {
        foreach ($cellValues as $cellRef => $value) {
            $this->setExportCellValue($sheet, $cellRef, $value);
        }
    }

    protected function sanitizeExcelSheetTitle(string $title): string
    {
        $sanitized = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $title) ?? $title;
        $sanitized = trim($sanitized);

        return mb_substr($sanitized !== '' ? $sanitized : 'Sheet', 0, 31);
    }

    public function acquisitionFilledSpreadsheet(Acquisition $acquisition, ?string $formSlug = null): Spreadsheet
    {
        $acquisition->loadMissing('item.category');
        $templateFilename = $this->getTemplatePathForCategory('acquisition', $acquisition->item?->category, $formSlug);

        if ($this->isAnnexA1PropertyCardTemplate($templateFilename)) {
            return $this->buildAnnexA1Spreadsheet(
                [$this->acquisitionAnnexA1ExportTab($acquisition)],
                $templateFilename,
            );
        }

        $cellValues = $this->cellValuesForAcquisition($acquisition, $templateFilename);
        $sheet = $this->resolveAcquisitionSheet($acquisition, $formSlug, $templateFilename);

        return $this->renderFilledSpreadsheet(
            $templateFilename,
            $cellValues,
            $sheet['sheetIndex'],
            $sheet['sheetName'],
        );
    }

    public function acquisitionSpreadsheetBinary(Acquisition $acquisition, ?string $formSlug = null): string
    {
        $spreadsheet = $this->acquisitionFilledSpreadsheet($acquisition, $formSlug);

        try {
            return $this->spreadsheetToXlsxBinary($spreadsheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function issuanceFilledSpreadsheet(Issuance $issuance, ?string $formSlug = null): Spreadsheet
    {
        $issuance->loadMissing('item.category');
        $templateFilename = $this->getTemplatePathForCategory('issuance', $issuance->item?->category, $formSlug);
        $cellValues = $this->cellValuesForIssuance($issuance, $templateFilename);

        return $this->renderFilledSpreadsheet($templateFilename, $cellValues);
    }

    /**
     * @param  Collection<int, Issuance>  $issuances
     */
    public function canMergeIssuancesIntoSingleRsmiSheet(Collection $issuances): bool
    {
        if ($issuances->count() < 2) {
            return false;
        }

        foreach ($issuances as $issuance) {
            $issuance->loadMissing('item.category');
            $templateFilename = $this->getTemplatePathForCategory('issuance', $issuance->item?->category);
            $path = str_replace('\\', '/', $templateFilename);

            if (! str_contains($path, 'RSMI') && ! str_contains($path, 'Appendix 64')) {
                return false;
            }
        }

        return true;
    }

    /**
     * One RSMI workbook with consecutive detail rows for all selected consumable issuances.
     *
     * @param  Collection<int, Issuance>  $issuances
     */
    public function downloadIssuancesRsmiMerged(Collection $issuances): StreamedResponse
    {
        $issuances = $issuances->sortBy('issuance_date')->values();
        $first = $issuances->first();
        $first->loadMissing('item.category');
        $templateFilename = $this->getTemplatePathForCategory('issuance', $first->item?->category);
        $cellValues = $this->cellValuesForIssuanceRsmiBulk($issuances);
        $filename = OwwaExportFilename::batch('RSMI');

        return $this->downloadFromTemplate($templateFilename, $cellValues, $filename);
    }

    public function issuanceSpreadsheetBinary(Issuance $issuance, ?string $formSlug = null): string
    {
        $spreadsheet = $this->issuanceFilledSpreadsheet($issuance, $formSlug);

        try {
            return $this->spreadsheetToXlsxBinary($spreadsheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function transferFilledSpreadsheet(Transfer $transfer, ?string $formSlug = null): Spreadsheet
    {
        $transfer->loadMissing('item.category');
        $templateFilename = $this->getTemplatePathForCategory('transfer', $transfer->item?->category, $formSlug);
        $cellValues = $this->cellValuesForTransfer($transfer, $templateFilename);

        return $this->renderFilledSpreadsheet($templateFilename, $cellValues);
    }

    public function transferSpreadsheetBinary(Transfer $transfer, ?string $formSlug = null): string
    {
        $spreadsheet = $this->transferFilledSpreadsheet($transfer, $formSlug);

        try {
            return $this->spreadsheetToXlsxBinary($spreadsheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function disposalFilledSpreadsheet(Disposal $disposal, ?string $formSlug = null): Spreadsheet
    {
        $disposal->loadMissing('item.category');
        $formSlug = $this->resolveDisposalFormSlug($disposal, $formSlug);
        $templateFilename = $this->getDisposalTemplatePath($disposal, $formSlug);
        $cellValues = $this->cellValuesForDisposal($disposal, $templateFilename);

        return $this->renderFilledSpreadsheet($templateFilename, $cellValues);
    }

    public function disposalSpreadsheetBinary(Disposal $disposal, ?string $formSlug = null): string
    {
        $spreadsheet = $this->disposalFilledSpreadsheet($disposal, $formSlug);

        try {
            return $this->spreadsheetToXlsxBinary($spreadsheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function requisitionFilledSpreadsheet(Requisition $requisition, ?string $templateFilename = null): Spreadsheet
    {
        if ($templateFilename === null) {
            $templateFilename = (string) config('owwa_templates.requisition.default.file', 'requisition/Appendix 63 - RIS.xls');
        }

        return $this->renderFilledSpreadsheet(
            $templateFilename,
            $this->cellValuesForRequisition($requisition),
        );
    }

    /**
     * Build cell values for a single Issuance record. Uses template-specific mapping when the path matches a known OWWA form (e.g. Appendix 64 - RSMI).
     *
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForIssuance(Issuance $issuance, ?string $templatePath = null): array
    {
        $issuance->load(['item', 'office', 'department', 'issuedBy', 'issuedTo']);

        $pathForMatch = $templatePath !== null ? str_replace('\\', '/', $templatePath) : '';

        if ($pathForMatch !== '') {
            if (str_contains($pathForMatch, 'RSMI') || str_contains($pathForMatch, 'Appendix 64')) {
                return $this->cellValuesForIssuanceRsmi($issuance);
            }
            if (str_contains($pathForMatch, 'PAR') || str_contains($pathForMatch, 'Appendix 71')) {
                return $this->cellValuesForIssuancePar($issuance);
            }
            if (str_contains($pathForMatch, 'ICS') || str_contains($pathForMatch, 'Appendix 59')) {
                return $this->cellValuesForIssuanceIcs($issuance);
            }
        }

        return [
            'B2' => $issuance->reference_code,
            'B3' => $issuance->issuance_date?->format('Y-m-d'),
            'B4' => $issuance->item?->name,
            'B5' => (string) $issuance->quantity,
            'B6' => $issuance->office?->name,
            'B7' => $issuance->department?->name ?? '',
            'B8' => $issuance->issuedTo?->name ?? '',
            'B9' => $issuance->issuedBy?->name ?? '',
            'B10' => $issuance->remarks ?? '',
        ];
    }

    /**
     * Cell mapping for Appendix 64 - Report of Supplies and Materials Issued (RSMI).
     * Headers span merged rows 10-11; first data row = 12. Recap headers span 34-35; recap data row = 36.
     *
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForIssuanceRsmi(Issuance $issuance): array
    {
        return $this->cellValuesForIssuanceRsmiBulk(collect([$issuance]));
    }

    /**
     * Appendix 64 RSMI — single or multiple issuance lines on one form.
     * Detail rows 12–32; recapitulation rows 36+ (one line per detail row, offset +24).
     *
     * @param  Collection<int, Issuance>  $issuances
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForIssuanceRsmiBulk(Collection $issuances): array
    {
        $user = Auth::user();
        $fillAccountingFields = ! ($user?->isSupplyCustodian() ?? false);

        $issuances = $issuances->sortBy('issuance_date')->values();
        $first = $issuances->first();
        $first->loadMissing(['requisition', 'department', 'item', 'office']);

        $office = $first->office;
        $reportDate = $issuances
            ->map(fn (Issuance $i): ?string => $i->issuance_date?->format('Y-m-d'))
            ->filter()
            ->sort()
            ->last() ?? now()->format('Y-m-d');

        $rsmiSerial = $this->ensureControlNumberFormat(
            $first->reference_code,
            $first->issuance_date?->format('Y-m-d'),
            'monthly'
        );

        $rsmiMap = OwwaCellMapping::form('RSMI');
        $values = [];
        OwwaCellMapping::applyHeader($values, (array) ($rsmiMap['header'] ?? []), [
            'entity_name' => $office?->name ?? '',
            'serial_no' => $rsmiSerial,
            'fund_cluster' => $office?->fund_cluster ?? $office?->name ?? '',
            'date' => $reportDate,
        ]);

        $detailConfig = (array) ($rsmiMap['detail'] ?? []);
        $detailStartRow = (int) ($detailConfig['start_row'] ?? 12);
        $detailEndRow = (int) ($detailConfig['end_row'] ?? 32);
        $recapStartRow = (int) ($detailConfig['recap_start_row'] ?? 36);
        $recapEndRow = (int) ($detailConfig['recap_end_row'] ?? 51);
        $detailColumns = (array) ($detailConfig['columns'] ?? []);
        $detailToRecapOffset = $recapStartRow - $detailStartRow;

        $row = $detailStartRow;

        foreach ($issuances as $issuance) {
            if ($row > $detailEndRow) {
                break;
            }

            $issuance->loadMissing(['requisition', 'department', 'item']);
            $item = $issuance->item;
            $lineOffice = $issuance->office;
            $unitCost = $issuance->unit_cost !== null ? (float) $issuance->unit_cost : null;
            $amount = $issuance->amount !== null ? (float) $issuance->amount : null;
            $responsibilityCenter = $issuance->department?->code ?? $lineOffice?->code ?? $lineOffice?->name ?? '';
            $risNumber = $issuance->requisition?->reference_code ?? '';
            $stockNo = $item?->item_code ?? '';
            $quantity = (int) $issuance->quantity;
            $lineAmount = $amount ?? ($unitCost !== null ? $unitCost * $quantity : null);

            $values[OwwaCellMapping::columnCell($detailColumns['ris_no'] ?? 'A', $row)] = $risNumber;
            $values[OwwaCellMapping::columnCell($detailColumns['responsibility_center'] ?? 'B', $row)] = $responsibilityCenter;
            $values[OwwaCellMapping::columnCell($detailColumns['stock_no'] ?? 'C', $row)] = $stockNo;
            $values[OwwaCellMapping::columnCell($detailColumns['item'] ?? 'D', $row)] = $item?->name ?? '';
            $values[OwwaCellMapping::columnCell($detailColumns['unit'] ?? 'E', $row)] = $item?->unit ?? '';
            $values[OwwaCellMapping::columnCell($detailColumns['quantity'] ?? 'F', $row)] = (string) $quantity;
            $values[OwwaCellMapping::columnCell($detailColumns['unit_cost'] ?? 'G', $row)] = $fillAccountingFields ? ($unitCost !== null ? $unitCost : '') : '';
            $values[OwwaCellMapping::columnCell($detailColumns['amount'] ?? 'H', $row)] = $fillAccountingFields ? ($lineAmount !== null ? $lineAmount : '') : '';

            $recapRow = $row + $detailToRecapOffset;
            if ($recapRow <= $recapEndRow) {
                $values[OwwaCellMapping::columnCell('B', $recapRow)] = $stockNo;
                $values[OwwaCellMapping::columnCell('C', $recapRow)] = (string) $quantity;
                $values[OwwaCellMapping::columnCell('F', $recapRow)] = $fillAccountingFields ? ($unitCost !== null ? $unitCost : '') : '';
                $values[OwwaCellMapping::columnCell('G', $recapRow)] = $fillAccountingFields ? ($lineAmount !== null ? $lineAmount : '') : '';
            }

            $row++;
        }

        $values['A52'] = $first->custodian_printed_name ?? '';
        $values['H52'] = $reportDate;

        return $values;
    }

    public function resolveOwwaFormCode(?string $templatePath): string
    {
        $path = str_replace('\\', '/', $templatePath ?? '');

        return match (true) {
            str_contains($path, 'RSMI') || str_contains($path, 'Appendix 64') => 'RSMI',
            str_contains($path, 'PAR') || str_contains($path, 'Appendix 71') => 'PAR',
            str_contains($path, 'ICS') || str_contains($path, 'Appendix 59') => 'ICS',
            str_contains($path, 'PTR') || str_contains($path, 'Appendix 76') => 'PTR',
            str_contains($path, 'WMR') || str_contains($path, 'Appendix 65') => 'WMR',
            str_contains($path, 'IIRUP') || str_contains($path, 'Appendix 74') => 'IIRUP',
            $this->isAnnexA1PropertyCardTemplate($path) => 'AnnexA1',
            str_contains($path, 'IIRUSP') || str_contains($path, 'Annex A.10') => 'IIRUSP',
            str_contains($path, 'RLSDDP') || str_contains($path, 'Appendix 75') => 'RLSDDP',
            str_contains($path, 'RIS') || str_contains($path, 'Appendix 63') => 'RIS',
            str_contains($path, 'SLC') || str_contains($path, 'Appendix 57') => 'SLC',
            str_contains($path, 'Appendix 58') || str_contains($path, '/SC.') => 'SC',
            str_contains($path, 'Appendix 69') => 'PC',
            default => 'OWWA',
        };
    }

    public function buildOwwaExportFilename(string $formCode, ?string $suffix = null): string
    {
        if ($suffix === null) {
            return OwwaExportFilename::batch($formCode);
        }

        return OwwaExportFilename::transaction($formCode, $suffix);
    }

    /**
     * Cell mapping for Appendix 71 - Property Acknowledgment Receipt (PAR). PPE issuance.
     * Headers span merged rows 9-10; first data row = 11.
     *
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForIssuancePar(Issuance $issuance): array
    {
        $item = $issuance->item;
        $office = $issuance->office;
        $dateAcquired = $this->lookupDateAcquired($issuance->item_id);
        $description = $this->formatItemDescription($item);

        $parMap = OwwaCellMapping::form('PAR');
        $detailStart = (int) ($parMap['detail']['start_row'] ?? 11);
        $cols = (array) ($parMap['detail']['columns'] ?? []);

        $values = [];
        OwwaCellMapping::applyHeader($values, (array) ($parMap['header'] ?? []), [
            'entity_name' => $office?->name ?? '',
            'fund_cluster' => $office?->fund_cluster ?? '',
            'par_no' => $this->ensureControlNumberFormat($issuance->reference_code, $issuance->issuance_date?->format('Y-m-d'), 'yearly'),
        ]);

        $values[OwwaCellMapping::columnCell($cols['quantity'] ?? 'A', $detailStart)] = (string) $issuance->quantity;
        $values[OwwaCellMapping::columnCell($cols['unit'] ?? 'B', $detailStart)] = $item?->unit ?? '';
        $values[OwwaCellMapping::columnCell($cols['description'] ?? 'C', $detailStart)] = $description;
        $values[OwwaCellMapping::columnCell($cols['property_number'] ?? 'D', $detailStart)] = $issuance->property_number ?? $item?->item_code ?? '';
        $values[OwwaCellMapping::columnCell($cols['date_acquired'] ?? 'E', $detailStart)] = $dateAcquired ?? '';
        $values[OwwaCellMapping::columnCell($cols['amount'] ?? 'F', $detailStart)] = $issuance->amount ?? $issuance->unit_cost ?? '';

        return array_merge($values, [
            'A45' => $issuance->issuedTo?->name ?? $issuance->custodian_printed_name ?? '',
            'D45' => $issuance->custodian_printed_name ?? $issuance->issuedBy?->name ?? '',
            'A48' => $issuance->issued_to_designation ?? $issuance->issuedTo?->office?->name ?? '',
            'D48' => $issuance->custodian_designation ?? $office?->name ?? '',
            'A50' => $issuance->issuance_date?->format('Y-m-d') ?? '',
            'D50' => $issuance->issuance_date?->format('Y-m-d') ?? '',
        ]);
    }

    /**
     * Cell mapping for Appendix 59 - Inventory Custodian Slip (ICS). Semi-expendable issuance.
     * Headers span merged rows 9-11; first data row = 12.
     * Columns: A=Quantity, B=Unit, C=Unit Cost, D=Total Cost, E(+F merged)=Description, G=Inventory Item No., H=Estimated Useful Life.
     *
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForIssuanceIcs(Issuance $issuance): array
    {
        $item = $issuance->item;
        $office = $issuance->office;
        $unitCost = $issuance->unit_cost !== null ? (float) $issuance->unit_cost : null;
        $totalCost = $issuance->amount !== null ? (float) $issuance->amount : ($unitCost !== null ? $unitCost * ($issuance->quantity ?? 1) : null);
        $usefulLife = $issuance->estimated_useful_life ?? $item?->estimated_useful_life ?? '';

        $icsMap = OwwaCellMapping::form('ICS');
        $detailStart = (int) ($icsMap['detail']['start_row'] ?? 12);
        $cols = (array) ($icsMap['detail']['columns'] ?? []);

        $values = [];
        OwwaCellMapping::applyHeader($values, (array) ($icsMap['header'] ?? []), [
            'entity_name' => $office?->name ?? '',
            'fund_cluster' => $office?->fund_cluster ?? '',
            'ics_no' => $this->ensureControlNumberFormat($issuance->reference_code, $issuance->issuance_date?->format('Y-m-d'), 'yearly'),
        ]);

        $values[OwwaCellMapping::columnCell($cols['quantity'] ?? 'A', $detailStart)] = (string) $issuance->quantity;
        $values[OwwaCellMapping::columnCell($cols['unit'] ?? 'B', $detailStart)] = $item?->unit ?? '';
        $values[OwwaCellMapping::columnCell($cols['unit_cost'] ?? 'C', $detailStart)] = $unitCost !== null ? $unitCost : '';
        $values[OwwaCellMapping::columnCell($cols['total_cost'] ?? 'D', $detailStart)] = $totalCost !== null ? $totalCost : '';
        $values[OwwaCellMapping::columnCell($cols['description'] ?? 'E', $detailStart)] = $this->formatItemDescription($item);
        $values[OwwaCellMapping::columnCell($cols['inventory_item_no'] ?? 'G', $detailStart)] = $issuance->property_number ?? $item?->item_code ?? '';
        $values[OwwaCellMapping::columnCell($cols['useful_life'] ?? 'H', $detailStart)] = $usefulLife;

        return array_merge($values, [
            'A44' => $issuance->received_from_name ?? $issuance->issuedBy?->name ?? '',
            'A46' => $issuance->issuedTo?->name ?? '',
            'F46' => $issuance->custodian_printed_name ?? $issuance->issuedBy?->name ?? '',
            'A49' => $issuance->issued_to_designation ?? '',
            'F49' => $issuance->custodian_designation ?? '',
            'A51' => $issuance->issuance_date?->format('Y-m-d') ?? '',
            'F51' => $issuance->issuance_date?->format('Y-m-d') ?? '',
        ]);
    }

    /**
     * Build cell values for a single Transfer record. Uses template-specific mapping for PTR (Appendix 76).
     *
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForTransfer(Transfer $transfer, ?string $templatePath = null): array
    {
        $transfer->loadMissing(['item', 'fromOffice', 'toOffice', 'recordedBy']);
        $pathForMatch = $templatePath !== null ? str_replace('\\', '/', $templatePath) : '';

        if ($pathForMatch !== '' && (str_contains($pathForMatch, 'PTR') || str_contains($pathForMatch, 'Appendix 76'))) {
            return $this->cellValuesForTransferPtr($transfer);
        }

        if ($pathForMatch !== '' && (str_contains($pathForMatch, 'RSMI') || str_contains($pathForMatch, 'Appendix 64'))) {
            return $this->cellValuesForTransferRsmi($transfer);
        }

        return [
            'B2' => $transfer->reference_code,
            'B3' => $transfer->transfer_date?->format('Y-m-d'),
            'B4' => $transfer->item?->name,
            'B5' => (string) $transfer->quantity,
            'B6' => $transfer->fromOffice?->name,
            'B7' => $transfer->toOffice?->name,
            'B8' => $transfer->recordedBy?->name ?? '',
            'B9' => $transfer->remarks ?? '',
        ];
    }

    /**
     * Build cell values for a single Acquisition record.
     *
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForAcquisition(Acquisition $acquisition, ?string $templatePath = null): array
    {
        $acquisition->load(['item', 'office', 'recordedBy']);

        if ($templatePath !== null) {
            if (str_contains($templatePath, 'Appendix 58') || str_contains($templatePath, 'SC')) {
                return $this->cellValuesForAcquisitionSc($acquisition);
            }
            if (str_contains($templatePath, 'Appendix 69') || str_contains($templatePath, 'PC')) {
                return $this->cellValuesForAcquisitionPc($acquisition);
            }
            if (str_contains($templatePath, 'Annex-A.1') || str_contains($templatePath, 'Annex A.1')) {
                return $this->cellValuesForAcquisitionAnnexA1($acquisition);
            }
        }

        return [
            'B2' => $acquisition->reference_code,
            'B3' => $acquisition->acquisition_date?->format('Y-m-d'),
            'B4' => $acquisition->item?->name,
            'B5' => (string) $acquisition->quantity,
            'B6' => $acquisition->unit_cost !== null ? (float) $acquisition->unit_cost : '',
            'B7' => $acquisition->office?->name,
            'B8' => $acquisition->source ?? '',
            'B9' => $acquisition->recordedBy?->name ?? '',
            'B10' => $acquisition->remarks ?? '',
        ];
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForAcquisitionSc(Acquisition $acquisition): array
    {
        $item = $acquisition->item;
        $office = $acquisition->office;
        $quantity = (int) ($acquisition->quantity ?? 0);

        $scMap = OwwaCellMapping::form('SC');
        $ledgerStart = (int) ($scMap['ledger']['start_row'] ?? 13);
        $ledgerCols = (array) ($scMap['ledger']['columns'] ?? []);

        $values = [];
        OwwaCellMapping::applyHeader($values, (array) ($scMap['header'] ?? []), [
            'entity_name' => $office?->name ?? '',
            'fund_cluster' => $office?->fund_cluster ?? '',
            'item' => $item?->name ?? '',
            'stock_no' => $item?->item_code ?? '',
            'description' => $item?->description ?? '',
            'reorder_point' => (string) ($item?->reorder_level ?? 0),
            'unit_of_measurement' => $item?->unit ?? '',
        ]);

        $values[OwwaCellMapping::columnCell($ledgerCols['date'] ?? 'A', $ledgerStart)] = $acquisition->acquisition_date?->format('Y-m-d') ?? '';
        $values[OwwaCellMapping::columnCell($ledgerCols['reference'] ?? 'B', $ledgerStart)] = $acquisition->reference_code;
        $values[OwwaCellMapping::columnCell($ledgerCols['receipt_qty'] ?? 'C', $ledgerStart)] = $quantity > 0 ? (string) $quantity : '';
        $values[OwwaCellMapping::columnCell($ledgerCols['balance_qty'] ?? 'F', $ledgerStart)] = $quantity > 0 ? (string) $quantity : '';

        return $values;
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForAcquisitionPc(Acquisition $acquisition): array
    {
        return PropertyCardLayout::buildFromAcquisition($acquisition);
    }

    /**
     * @return array<string, string|int|float|null>
     */
    /**
     * @return array{sheetName: string, cellValues: array<string, string|int|float|null>}
     */
    protected function acquisitionAnnexA1ExportTab(Acquisition $acquisition): array
    {
        $item = $acquisition->item;
        $propertyClass = \App\Support\ItemPropertyClass::resolveForExport($item?->property_class);
        $sheetName = \App\Support\ItemPropertyClass::sheetNameForForm('annex_a1', $propertyClass) ?? 'OFFICE EQUIPMENT';

        return [
            'sheetName' => $sheetName,
            'cellValues' => $this->cellValuesForAcquisitionAnnexA1($acquisition),
        ];
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForAcquisitionAnnexA1(Acquisition $acquisition): array
    {
        $item = $acquisition->item;
        $office = $acquisition->office;
        $propertyClass = \App\Support\ItemPropertyClass::resolveForExport($item?->property_class);
        $ledgerCols = (array) (OwwaCellMapping::form('ANNEX_A1')['ledger']['columns'] ?? []);
        $ledgerStart = AnnexA1BlockLayout::ledgerStartRow(0);
        $quantity = (int) ($acquisition->quantity ?? 0);
        $unitCost = $acquisition->unit_cost !== null ? (float) $acquisition->unit_cost : null;

        $values = [];
        AnnexA1BlockLayout::applyHeader($values, [
            'entity_name' => $office?->name ?? '',
            'fund_cluster' => $office?->fund_cluster ?? '',
            'property_type' => \App\Support\ItemPropertyClass::propertyTypeLabel($propertyClass),
            'property_number' => $item?->item_code ?? '',
            'description' => $this->formatItemDescription($item),
        ], 0);

        $values[OwwaCellMapping::columnCell($ledgerCols['date'] ?? 'A', $ledgerStart)] = $acquisition->acquisition_date?->format('Y-m-d') ?? '';
        $values[OwwaCellMapping::columnCell($ledgerCols['reference'] ?? 'B', $ledgerStart)] = $acquisition->reference_code ?? '';

        if ($quantity > 0) {
            $values[OwwaCellMapping::columnCell($ledgerCols['receipt_qty'] ?? 'C', $ledgerStart)] = $quantity;
            $values[OwwaCellMapping::columnCell($ledgerCols['receipt_qty_dup'] ?? 'F', $ledgerStart)] = $quantity;
            $values[OwwaCellMapping::columnCell($ledgerCols['balance_qty'] ?? 'J', $ledgerStart)] = $quantity;

            if ($unitCost !== null) {
                $values[OwwaCellMapping::columnCell($ledgerCols['unit_cost'] ?? 'D', $ledgerStart)] = $unitCost;
                $values[OwwaCellMapping::columnCell($ledgerCols['total_cost'] ?? 'E', $ledgerStart)] = $unitCost * $quantity;
            }
        }

        if (filled($item?->item_code)) {
            $values[OwwaCellMapping::columnCell($ledgerCols['item_no'] ?? 'G', $ledgerStart)] = $item->item_code;
        }

        $values[OwwaCellMapping::columnCell($ledgerCols['remarks'] ?? 'L', $ledgerStart)] = $acquisition->remarks ?? '';

        return $values;
    }

    /**
     * Cell mapping for Appendix 76 - Property Transfer Report (PTR).
     * Headers span merged rows 16-17; first data row = 18.
     * Data row merges: B:C and D:G — write to master cells B and D.
     *
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForTransferPtr(Transfer $transfer): array
    {
        $item = $transfer->item;
        $from = $transfer->fromOffice;
        $to = $transfer->toOffice;
        $dateAcquired = $this->lookupDateAcquired($transfer->item_id);
        $acquisitionCost = $this->lookupAcquisitionCost($transfer->item_id, $transfer->quantity);
        $description = $this->formatItemDescription($item);
        if ($transfer->quantity) {
            $description .= ' (Qty: '.$transfer->quantity.')';
        }
        $reason = $transfer->reason_for_transfer ?? $transfer->remarks ?? '';

        $ptrMap = OwwaCellMapping::form('PTR');
        $detailStart = (int) ($ptrMap['detail']['start_row'] ?? 17);
        $detailCols = (array) ($ptrMap['detail']['columns'] ?? []);

        $values = [];
        OwwaCellMapping::applyHeader($values, (array) ($ptrMap['header'] ?? []), [
            'entity_name' => $from?->name ?? $to?->name ?? '',
            'fund_cluster' => $from?->fund_cluster ?? $to?->fund_cluster ?? '',
            'from_accountable' => $transfer->from_accountable_officer ?? $from?->name ?? '',
            'ptr_no' => $this->ensureControlNumberFormat($transfer->reference_code, $transfer->transfer_date?->format('Y-m-d'), 'yearly'),
            'to_accountable' => $transfer->to_accountable_officer ?? $to?->name ?? '',
            'date' => $transfer->transfer_date?->format('Y-m-d') ?? '',
        ]);

        $values[OwwaCellMapping::columnCell($detailCols['date_acquired'] ?? 'A', $detailStart)] = $dateAcquired ?? '';
        $values[OwwaCellMapping::columnCell($detailCols['property_no'] ?? 'B', $detailStart)] = $transfer->property_number ?? $item?->item_code ?? '';
        $values[OwwaCellMapping::columnCell($detailCols['description'] ?? 'D', $detailStart)] = $description;
        $values[OwwaCellMapping::columnCell($detailCols['amount'] ?? 'H', $detailStart)] = $acquisitionCost !== null ? $acquisitionCost : '';
        $values[OwwaCellMapping::columnCell($detailCols['condition'] ?? 'I', $detailStart)] = $transfer->condition ?? '';

        $values = PtrSignatureLayout::applyTransferTypeMarks($values, $transfer);

        return PtrSignatureLayout::applySignatureBlock($values, $transfer);
    }

    /**
     * Consumable inter-office transfer — one RSMI detail line (Appendix 64 stand-in).
     *
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForTransferRsmi(Transfer $transfer): array
    {
        $transfer->loadMissing(['item', 'fromOffice', 'toOffice', 'recordedBy']);
        $item = $transfer->item;
        $from = $transfer->fromOffice;
        $to = $transfer->toOffice;
        $rsmiMap = OwwaCellMapping::form('RSMI');
        $detailStart = (int) ($rsmiMap['detail']['start_row'] ?? 12);
        $cols = (array) ($rsmiMap['detail']['columns'] ?? []);

        $values = [];
        OwwaCellMapping::applyHeader($values, (array) ($rsmiMap['header'] ?? []), [
            'entity_name' => $from?->name ?? $to?->name ?? '',
            'serial_no' => $this->ensureControlNumberFormat($transfer->reference_code, $transfer->transfer_date?->format('Y-m-d'), 'monthly'),
            'fund_cluster' => $from?->fund_cluster ?? $to?->fund_cluster ?? '',
            'date' => $transfer->transfer_date?->format('Y-m-d') ?? '',
        ]);

        $description = $this->formatItemDescription($item);
        if ($to?->name) {
            $description .= ' — Transfer to '.$to->name;
        }

        $values[OwwaCellMapping::columnCell($cols['ris_no'] ?? 'A', $detailStart)] = $transfer->reference_code ?? '';
        $values[OwwaCellMapping::columnCell($cols['responsibility_center'] ?? 'B', $detailStart)] = $from?->code ?? $from?->name ?? '';
        $values[OwwaCellMapping::columnCell($cols['stock_no'] ?? 'C', $detailStart)] = $item?->item_code ?? '';
        $values[OwwaCellMapping::columnCell($cols['item'] ?? 'D', $detailStart)] = $description;
        $values[OwwaCellMapping::columnCell($cols['unit'] ?? 'E', $detailStart)] = $item?->unit ?? '';
        $values[OwwaCellMapping::columnCell($cols['quantity'] ?? 'F', $detailStart)] = (string) $transfer->quantity;

        $values['A52'] = $transfer->released_by_printed_name ?? $transfer->recordedBy?->name ?? '';

        return $values;
    }

    /**
     * Build cell values for a single Disposal record. Uses template-specific mapping when the path matches a known OWWA form (e.g. Appendix 65 - WMR).
     *
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForDisposal(Disposal $disposal, ?string $templatePath = null): array
    {
        $disposal->load(['item', 'office', 'recordedBy']);
        if ($templatePath !== null) {
            if (str_contains($templatePath, 'WMR') || str_contains($templatePath, 'Appendix 65')) {
                return $this->cellValuesForDisposalWmr($disposal);
            }
            if (str_contains($templatePath, 'IIRUP') || str_contains($templatePath, 'Appendix 74')) {
                return $this->cellValuesForDisposalIirup($disposal);
            }
            if (str_contains($templatePath, 'IIRUSP') || str_contains($templatePath, 'Annex A.10')) {
                return $this->cellValuesForDisposalIirusp($disposal);
            }
            if (str_contains($templatePath, 'RLSDDP') || str_contains($templatePath, 'Appendix 75')) {
                return $this->cellValuesForDisposalRlsddp($disposal);
            }
        }

        return [
            'B2' => $disposal->reference_code,
            'B3' => $disposal->disposal_date?->format('Y-m-d'),
            'B4' => $disposal->item?->name,
            'B5' => (string) $disposal->quantity,
            'B6' => $disposal->office?->name,
            'B7' => $disposal->reason ?? '',
            'B8' => $disposal->recordedBy?->name ?? '',
            'B9' => $disposal->remarks ?? '',
        ];
    }

    /**
     * Cell mapping for Appendix 65 - Waste Materials Report (WMR).
     * Headers span merged rows 10-12; first data row = 13.
     * Columns: A=Item#, B=Quantity, C=Unit, D(-F)=Description, G=Receipt No., H=Receipt Date, I=Amount.
     *
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForDisposalWmr(Disposal $disposal): array
    {
        $disposal->loadMissing(['item', 'office']);

        return DisposalExportLayout::cellValuesForWmr($disposal);
    }

    /**
     * Cell mapping for Appendix 74 - IIRUP (Unserviceable Property).
     * Headers span merged rows 11-13; column numbers in row 14; first data row = 15.
     *
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForDisposalIirup(Disposal $disposal): array
    {
        $disposal->loadMissing(['parIssuance', 'item', 'office']);

        return DisposalExportLayout::cellValuesForIirup($disposal, 'IIRUP');
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForDisposalIirusp(Disposal $disposal): array
    {
        $disposal->loadMissing(['item', 'office']);

        return DisposalExportLayout::cellValuesForIirup($disposal, 'IIRUSP');
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForDisposalRlsddp(Disposal $disposal): array
    {
        $disposal->loadMissing(['parIssuance', 'item', 'office']);

        return DisposalExportLayout::cellValuesForRlsddp($disposal);
    }

    /**
     * Build cell values for a single Requisition record for Appendix 63 - RIS.
     * This fills only inventory / administrative fields (entity, office, items, quantities)
     * and intentionally leaves accounting-only areas blank.
     *
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForRequisition(Requisition $requisition): array
    {
        $requisition->loadMissing([
            'office',
            'department',
            'requestedBy',
            'approvedBy',
            'items.item',
        ]);

        $office = $requisition->office;
        $department = $requisition->department;
        $responsibilityCenterCode = $department?->code ?? $office?->code ?? '';
        $risMap = OwwaCellMapping::form('RIS');

        $values = [];
        OwwaCellMapping::applyHeader($values, (array) ($risMap['header'] ?? []), [
            'entity_name' => $office?->name ?? '',
            'fund_cluster' => $office?->fund_cluster ?? '',
            'division' => $department?->name ?? '',
            'responsibility_center_code' => $responsibilityCenterCode,
            'office' => $office?->name ?? '',
            'ris_no' => $this->ensureControlNumberFormat(
                $requisition->reference_code,
                $requisition->created_at?->format('Y-m-d'),
                'yearly'
            ),
            'purpose' => $requisition->purpose ?? $requisition->remarks ?? '',
        ]);

        $detail = (array) ($risMap['detail'] ?? []);
        $startRow = (int) ($detail['start_row'] ?? 12);
        $maxRows = (int) ($detail['max_rows'] ?? 19);
        $columns = (array) ($detail['columns'] ?? []);
        $rowIndex = 0;

        foreach ($requisition->items as $itemLine) {
            if ($rowIndex >= $maxRows) {
                break;
            }

            $row = $startRow + $rowIndex;
            $item = $itemLine->item;

            $values[OwwaCellMapping::columnCell($columns['stock_no'] ?? 'A', $row)] = $item?->item_code ?? '';
            $values[OwwaCellMapping::columnCell($columns['unit'] ?? 'B', $row)] = $item?->unit ?? '';
            $values[OwwaCellMapping::columnCell($columns['description'] ?? 'C', $row)] = $item?->name ?? '';
            $values[OwwaCellMapping::columnCell($columns['quantity'] ?? 'D', $row)] = (string) $itemLine->quantity;

            if ($itemLine->stock_available !== null) {
                $yesCol = $columns['stock_yes'] ?? 'E';
                $noCol = $columns['stock_no_col'] ?? 'F';
                if ((int) $itemLine->stock_available > 0) {
                    $values[OwwaCellMapping::columnCell($yesCol, $row)] = 'X';
                } else {
                    $values[OwwaCellMapping::columnCell($noCol, $row)] = 'X';
                }
            }

            if ($itemLine->quantity_issued !== null) {
                $values[OwwaCellMapping::columnCell($columns['issue_quantity'] ?? 'G', $row)] = (string) $itemLine->quantity_issued;
            }

            $issueRemarks = $itemLine->issue_remarks ?? $itemLine->remarks ?? '';
            if ($issueRemarks !== '') {
                $values[OwwaCellMapping::columnCell($columns['issue_remarks'] ?? 'H', $row)] = $issueRemarks;
            }

            $rowIndex++;
        }

        $signatures = (array) ($risMap['signatures'] ?? []);
        if (isset($signatures['requested_by'])) {
            $values[$signatures['requested_by']] = $requisition->requestedBy?->name ?? '';
        }
        if (isset($signatures['approved_by'])) {
            $values[$signatures['approved_by']] = $requisition->approvedBy?->name ?? '';
        }

        return $values;
    }

    /**
     * Resolve template path from config (OWWA appendix names) or fallback to slug-based naming.
     *
     * @param  string|null  $formSlug  Optional form key (e.g. 'default', 'sc'). Null or empty = default form.
     */
    public function getTemplatePathForCategory(
        string $transactionType,
        ?\App\Models\ItemCategory $category,
        ?string $formSlug = null
    ): string {
        if ($transactionType === 'incident_report') {
            $formKey = ($formSlug === null || $formSlug === '') ? 'default' : $formSlug;
            $fromConfig = config("owwa_templates.incident_report.{$formKey}", null);
            if (is_array($fromConfig) && ! empty($fromConfig['file'])) {
                return (string) $fromConfig['file'];
            }

            return (string) config('owwa_templates.incident_report.default.file');
        }

        $slug = $category?->getTemplateSlug() ?? 'consumables';
        $formKey = ($formSlug === null || $formSlug === '') ? 'default' : $formSlug;

        $fromConfig = config("owwa_templates.{$transactionType}.{$slug}.{$formKey}", null);
        if (is_array($fromConfig) && ! empty($fromConfig['file'])) {
            return (string) $fromConfig['file'];
        }

        if ($formSlug !== null && $formSlug !== '' && $formSlug !== 'default') {
            return $slug.'/'.$formSlug.'.xlsx';
        }

        $folder = match ($slug) {
            'consumables' => 'Consumable',
            'semi_expendable' => 'Semi-Expendable',
            default => $slug,
        };

        return $folder.'/'.$slug.'.xlsx';
    }

    protected function isAnnexA1PropertyCardTemplate(string $templatePath): bool
    {
        return str_contains($templatePath, 'Property-Form-Annex-A.1');
    }

    /**
     * @return array{sheetIndex: int, sheetName: ?string}
     */
    protected function resolveAnnexA1SheetForItem(?\App\Models\Item $item): array
    {
        return [
            'sheetIndex' => 0,
            'sheetName' => AnnexA1BlockLayout::templateSheetName(),
        ];
    }

    /**
     * @return array{sheetIndex: int, sheetName: ?string}
     */
    protected function resolveAcquisitionSheet(Acquisition $acquisition, ?string $formSlug, string $templateFilename): array
    {
        $sheet = $this->resolveTemplateSheetForCategory('acquisition', $acquisition->item?->category, $formSlug);

        if ($this->isAnnexA1PropertyCardTemplate($templateFilename)) {
            return $this->resolveAnnexA1SheetForItem($acquisition->item);
        }

        return $sheet;
    }

    protected function clearAnnexA1SampleData(Worksheet $sheet): void
    {
        $clearTo = AnnexA1BlockLayout::clearToRow();
        $clearFrom = AnnexA1BlockLayout::ledgerStartRow(0);

        foreach (['entity_name', 'fund_cluster', 'property_type', 'property_number', 'description'] as $field) {
            $sheet->setCellValue(AnnexA1BlockLayout::headerCell($field, 0), null);
        }

        for ($row = $clearFrom; $row <= $clearTo; $row++) {
            foreach (range('A', 'L') as $column) {
                $sheet->setCellValue($column.$row, null);
            }
        }
    }

    /**
     * @return array{sheetIndex:int, sheetName:?string}
     */
    protected function resolveTemplateSheetForCategory(
        string $transactionType,
        ?\App\Models\ItemCategory $category,
        ?string $formSlug = null
    ): array {
        if ($transactionType === 'incident_report') {
            $formKey = ($formSlug === null || $formSlug === '') ? 'default' : $formSlug;
            $entry = config("owwa_templates.incident_report.{$formKey}", []);

            return [
                'sheetIndex' => (int) ($entry['sheet_index'] ?? 0),
                'sheetName' => isset($entry['sheet_name']) ? (string) $entry['sheet_name'] : null,
            ];
        }

        $slug = $category?->getTemplateSlug() ?? 'consumables';
        $formKey = ($formSlug === null || $formSlug === '') ? 'default' : $formSlug;
        $entry = config("owwa_templates.{$transactionType}.{$slug}.{$formKey}", []);

        return [
            'sheetIndex' => (int) ($entry['sheet_index'] ?? 0),
            'sheetName' => isset($entry['sheet_name']) ? (string) $entry['sheet_name'] : null,
        ];
    }

    /**
     * List available forms for a category (from config with OWWA labels, or from directory scan).
     *
     * @return array<string, string> Map of form_slug => label (e.g. ['' => 'Default', 'sc' => 'Appendix 58 - SC'])
     */
    public function getAvailableFormsForCategory(string $transactionType, ?\App\Models\ItemCategory $category): array
    {
        if ($transactionType === 'incident_report') {
            $configForms = config('owwa_templates.incident_report', []);

            return $this->mapConfigFormsToOptions(is_array($configForms) ? $configForms : []);
        }

        $slug = $category?->getTemplateSlug() ?? 'consumables';
        $configForms = config("owwa_templates.{$transactionType}.{$slug}", null);
        if (is_array($configForms)) {
            return $this->mapConfigFormsToOptions($configForms);
        }

        $dir = $this->templatesPath.DIRECTORY_SEPARATOR.$slug;
        $forms = [];
        if (! is_dir($dir)) {
            return ['' => 'Default'];
        }
        $files = array_merge(
            glob($dir.DIRECTORY_SEPARATOR.'*.xlsx') ?: [],
            glob($dir.DIRECTORY_SEPARATOR.'*.xls') ?: []
        );
        if ($files === []) {
            return ['' => 'Default'];
        }
        sort($files);
        foreach ($files as $path) {
            $basename = pathinfo($path, PATHINFO_FILENAME);
            $forms[$basename] = $basename;
        }
        $forms = ['' => 'Default'] + $forms;

        return $forms;
    }

    /**
     * List all form options for a transaction type (from config or directory scan). Used for dropdown when record context is not available.
     *
     * @return array<string, string> Map of form_slug => label
     */
    public function getAvailableFormSlugsGlobally(string $transactionType): array
    {
        $configTypes = config("owwa_templates.{$transactionType}", null);
        if (is_array($configTypes)) {
            $forms = ['' => 'Default'];
            foreach ($configTypes as $categoryForms) {
                if (! is_array($categoryForms)) {
                    continue;
                }
                foreach ($categoryForms as $formKey => $entry) {
                    $value = $formKey === 'default' ? '' : $formKey;
                    if (isset($forms[$value])) {
                        continue;
                    }
                    $label = is_array($entry) && isset($entry['label']) ? $entry['label'] : (is_array($entry) && isset($entry['file']) ? pathinfo($entry['file'], PATHINFO_FILENAME) : ucfirst(str_replace('_', ' ', $formKey)));
                    $forms[$value] = $label;
                }
            }
            ksort($forms);

            return $forms;
        }

        $forms = ['' => 'Default'];
        foreach (['consumables', 'ppe', 'semi_expendable'] as $cat) {
            $dir = $this->templatesPath.DIRECTORY_SEPARATOR.$cat;
            if (! is_dir($dir)) {
                continue;
            }
            $files = array_merge(
                glob($dir.DIRECTORY_SEPARATOR.'*.xlsx') ?: [],
                glob($dir.DIRECTORY_SEPARATOR.'*.xls') ?: []
            );
            foreach ($files as $path) {
                $basename = pathinfo($path, PATHINFO_FILENAME);
                if ($basename !== '' && ! isset($forms[$basename])) {
                    $forms[$basename] = $basename;
                }
            }
        }
        ksort($forms);

        return $forms;
    }

    /**
     * Export a single issuance as OWWA-format Excel using the template for the item's category and optional form.
     */
    public function downloadIssuance(Issuance $issuance, ?string $templateFilename = null, ?string $formSlug = null): StreamedResponse
    {
        $issuance->loadMissing('item.category');
        $sheet = $this->resolveTemplateSheetForCategory('issuance', $issuance->item?->category, $formSlug);
        if ($templateFilename === null) {
            $templateFilename = $this->getTemplatePathForCategory('issuance', $issuance->item?->category, $formSlug);
        }
        $formCode = $this->resolveOwwaFormCode($templateFilename);
        $filename = $this->buildOwwaExportFilename($formCode, $issuance->reference_code ?? (string) $issuance->getKey());

        return $this->downloadFromTemplate(
            $templateFilename,
            $this->cellValuesForIssuance($issuance, $templateFilename),
            $filename,
            $sheet['sheetIndex'],
            $sheet['sheetName']
        );
    }

    /**
     * Export a single acquisition as OWWA-format Excel using the template for the item's category and optional form.
     */
    public function downloadAcquisition(Acquisition $acquisition, ?string $templateFilename = null, ?string $formSlug = null): StreamedResponse
    {
        $acquisition->loadMissing('item.category');
        if ($templateFilename === null) {
            $templateFilename = $this->getTemplatePathForCategory('acquisition', $acquisition->item?->category, $formSlug);
        }
        $formCode = $this->resolveOwwaFormCode($templateFilename);
        $filename = $this->buildOwwaExportFilename($formCode, $acquisition->reference_code ?? (string) $acquisition->getKey());

        if ($this->isAnnexA1PropertyCardTemplate($templateFilename)) {
            return $this->downloadAnnexA1Spreadsheet(
                [$this->acquisitionAnnexA1ExportTab($acquisition)],
                $filename,
                $templateFilename,
            );
        }

        $sheet = $this->resolveAcquisitionSheet($acquisition, $formSlug, $templateFilename);

        return $this->downloadFromTemplate(
            $templateFilename,
            $this->cellValuesForAcquisition($acquisition, $templateFilename),
            $filename,
            $sheet['sheetIndex'],
            $sheet['sheetName']
        );
    }

    /**
     * Export a single transfer as OWWA-format Excel using the template for the item's category and optional form.
     */
    public function downloadTransfer(Transfer $transfer, ?string $templateFilename = null, ?string $formSlug = null): StreamedResponse
    {
        $transfer->loadMissing('item.category');
        $sheet = $this->resolveTemplateSheetForCategory('transfer', $transfer->item?->category, $formSlug);
        if ($templateFilename === null) {
            $templateFilename = $this->getTemplatePathForCategory('transfer', $transfer->item?->category, $formSlug);
        }
        $formCode = $this->resolveOwwaFormCode($templateFilename);
        $filename = $this->buildOwwaExportFilename($formCode, $transfer->reference_code ?? (string) $transfer->getKey());

        return $this->downloadFromTemplate(
            $templateFilename,
            $this->cellValuesForTransfer($transfer, $templateFilename),
            $filename,
            $sheet['sheetIndex'],
            $sheet['sheetName']
        );
    }

    /**
     * Export a single disposal as OWWA-format Excel using the template for the item's category and optional form.
     */
    public function downloadDisposal(Disposal $disposal, ?string $templateFilename = null, ?string $formSlug = null): StreamedResponse
    {
        $disposal->loadMissing('item.category');
        $formSlug = $this->resolveDisposalFormSlug($disposal, $formSlug);
        $transactionType = $disposal->disposal_type === 'lost_stolen_damaged' ? 'incident_report' : 'disposal';
        $sheet = $this->resolveTemplateSheetForCategory($transactionType, $disposal->item?->category, $formSlug);
        if ($templateFilename === null) {
            $templateFilename = $this->getDisposalTemplatePath($disposal, $formSlug);
        }
        $cellValues = $this->cellValuesForDisposal($disposal, $templateFilename);
        $formCode = $this->resolveOwwaFormCode($templateFilename);
        $filename = $this->buildOwwaExportFilename($formCode, $disposal->reference_code ?? (string) $disposal->getKey());

        return $this->downloadFromTemplate(
            $templateFilename,
            $cellValues,
            $filename,
            $sheet['sheetIndex'],
            $sheet['sheetName']
        );
    }

    public function getDisposalTemplatePath(Disposal $disposal, ?string $formSlug = null): string
    {
        if ($disposal->disposal_type === 'lost_stolen_damaged') {
            $formKey = ($formSlug === null || $formSlug === '') ? 'rlsddp' : $formSlug;

            return $this->getTemplatePathForCategory('incident_report', null, $formKey);
        }

        $disposal->loadMissing('item.category');
        $formKey = $formSlug ?? $this->resolveDisposalFormSlug($disposal, null);

        return $this->getTemplatePathForCategory('disposal', $disposal->item?->category, $formKey);
    }

    public function resolveDisposalFormSlug(Disposal $disposal, ?string $formSlug = null): string
    {
        if ($formSlug !== null && $formSlug !== '') {
            return $formSlug;
        }

        return match ($disposal->disposal_type) {
            'waste_sale' => 'wmr',
            'unserviceable' => 'iirup',
            'lost_stolen_damaged' => 'rlsddp',
            default => 'default',
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $configForms
     * @return array<string, string>
     */
    protected function mapConfigFormsToOptions(array $configForms): array
    {
        $forms = [];
        $hasNonDefault = false;

        foreach ($configForms as $formKey => $entry) {
            if ($formKey === 'default') {
                continue;
            }

            $hasNonDefault = true;
            $value = $formKey;
            $label = is_array($entry) && isset($entry['label'])
                ? $entry['label']
                : (is_array($entry) && isset($entry['file'])
                    ? pathinfo($entry['file'], PATHINFO_FILENAME)
                    : ucfirst(str_replace('_', ' ', $formKey)));

            $forms[$value] = $label;
        }

        if (! $hasNonDefault && isset($configForms['default'])) {
            $entry = $configForms['default'];
            $value = '';
            $label = is_array($entry) && isset($entry['label'])
                ? $entry['label']
                : (is_array($entry) && isset($entry['file'])
                    ? pathinfo($entry['file'], PATHINFO_FILENAME)
                    : 'Default');

            $forms[$value] = $label;
        }

        if ($forms === []) {
            return ['' => 'Default'];
        }

        ksort($forms);

        return $forms;
    }

    /**
     * Look up the most recent acquisition date for an item.
     */
    protected function lookupDateAcquired(?int $itemId): ?string
    {
        if ($itemId === null) {
            return null;
        }

        $date = Acquisition::query()
            ->where('item_id', $itemId)
            ->orderByDesc('acquisition_date')
            ->value('acquisition_date');

        return $date?->format('Y-m-d');
    }

    /**
     * Look up acquisition cost (unit_cost × quantity) from the most recent acquisition.
     */
    protected function lookupAcquisitionCost(?int $itemId, ?int $quantity): ?float
    {
        if ($itemId === null) {
            return null;
        }

        $unitCost = Acquisition::query()
            ->where('item_id', $itemId)
            ->orderByDesc('acquisition_date')
            ->value('unit_cost');

        if ($unitCost === null) {
            return null;
        }

        return (float) $unitCost * ($quantity ?? 1);
    }

    /**
     * Template directory path (for documentation or file existence checks).
     */
    public function getTemplatesPath(): string
    {
        return $this->templatesPath;
    }

    /**
     * Export a single requisition as Appendix 63 - RIS using the dedicated template.
     */
    public function downloadRequisition(Requisition $requisition, ?string $templateFilename = null): StreamedResponse
    {
        if ($templateFilename === null) {
            $templateFilename = (string) config('owwa_templates.requisition.default.file', 'requisition/Appendix 63 - RIS.xls');
        }
        $sheetIndex = (int) config('owwa_templates.requisition.default.sheet_index', 0);
        $sheetName = config('owwa_templates.requisition.default.sheet_name');
        $sheetName = is_string($sheetName) ? $sheetName : null;

        $formCode = $this->resolveOwwaFormCode($templateFilename);
        $filename = $this->buildOwwaExportFilename($formCode, $requisition->reference_code ?? (string) $requisition->getKey());

        return $this->downloadFromTemplate(
            $templateFilename,
            $this->cellValuesForRequisition($requisition),
            $filename,
            $sheetIndex,
            $sheetName
        );
    }

    /**
     * Export a distribution using the OWWA issuance form for the item category (RSMI/PAR/ICS).
     */
    public function downloadDistribution(\App\Models\Distribution $distribution, ?string $formSlug = null): StreamedResponse
    {
        $distribution->loadMissing(['item.category', 'office', 'department', 'distributedTo', 'distributedBy', 'requisition']);
        $templateFilename = $this->getTemplatePathForCategory('distribution', $distribution->item?->category, $formSlug);
        $sheet = $this->resolveTemplateSheetForCategory('distribution', $distribution->item?->category, $formSlug);
        $cellValues = $this->cellValuesForDistribution($distribution, $templateFilename);
        $formCode = $this->resolveOwwaFormCode($templateFilename);
        $reference = $distribution->requisition?->reference_code ?? 'DIST-'.$distribution->getKey();
        $filename = $this->buildOwwaExportFilename($formCode, $reference);

        return $this->downloadFromTemplate($templateFilename, $cellValues, $filename, $sheet['sheetIndex'], $sheet['sheetName']);
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForDistribution(\App\Models\Distribution $distribution, ?string $templatePath = null): array
    {
        $pathForMatch = $templatePath !== null ? str_replace('\\', '/', $templatePath) : '';
        $pseudoIssuance = new Issuance([
            'reference_code' => 'DIST-'.$distribution->id,
            'item_id' => $distribution->item_id,
            'office_id' => $distribution->office_id,
            'department_id' => $distribution->department_id,
            'quantity' => $distribution->quantity,
            'issuance_date' => $distribution->distribution_date,
            'remarks' => $distribution->remarks,
            'issued_to' => $distribution->distributed_to,
            'issued_by' => $distribution->distributed_by,
            'custodian_printed_name' => $distribution->distributedBy?->name,
            'issued_to_designation' => $distribution->distributedTo?->department?->name,
        ]);
        $pseudoIssuance->setRelation('item', $distribution->item);
        $pseudoIssuance->setRelation('office', $distribution->office);
        $pseudoIssuance->setRelation('department', $distribution->department);
        $pseudoIssuance->setRelation('issuedTo', $distribution->distributedTo);
        $pseudoIssuance->setRelation('issuedBy', $distribution->distributedBy);

        if (str_contains($pathForMatch, 'PAR') || str_contains($pathForMatch, 'Appendix 71')) {
            return $this->cellValuesForIssuancePar($pseudoIssuance);
        }
        if (str_contains($pathForMatch, 'ICS') || str_contains($pathForMatch, 'Appendix 59')) {
            return $this->cellValuesForIssuanceIcs($pseudoIssuance);
        }

        return $this->cellValuesForIssuanceRsmi($pseudoIssuance);
    }

    public function downloadAcquisitionPaperworkPr(AcquisitionPaperwork $paperwork): StreamedResponse
    {
        return $this->downloadAcquisitionPaperworkForm($paperwork, 'pr');
    }

    public function downloadAcquisitionPaperworkPo(AcquisitionPaperwork $paperwork): StreamedResponse
    {
        return $this->downloadAcquisitionPaperworkForm($paperwork, 'po');
    }

    public function downloadAcquisitionPaperworkIar(AcquisitionPaperwork $paperwork): StreamedResponse
    {
        return $this->downloadAcquisitionPaperworkForm($paperwork, 'iar');
    }

    /** @deprecated Use downloadAcquisitionPaperworkPr() */
    public function downloadProcurementPr(AcquisitionPaperwork $case): StreamedResponse
    {
        return $this->downloadAcquisitionPaperworkPr($case);
    }

    /** @deprecated Use downloadAcquisitionPaperworkPo() */
    public function downloadProcurementPo(AcquisitionPaperwork $case): StreamedResponse
    {
        return $this->downloadAcquisitionPaperworkPo($case);
    }

    /** @deprecated Use downloadAcquisitionPaperworkIar() */
    public function downloadProcurementIar(AcquisitionPaperwork $case): StreamedResponse
    {
        return $this->downloadAcquisitionPaperworkIar($case);
    }

    protected function downloadAcquisitionPaperworkForm(AcquisitionPaperwork $paperwork, string $formSlug): StreamedResponse
    {
        $paperwork->loadMissing(['office', 'department', 'itemCategory', 'lines.item']);
        $templateFilename = $this->getTemplatePathForCategory('acquisition_paperwork', $paperwork->itemCategory, $formSlug);
        $this->requireTemplatePath($templateFilename);
        $cellValues = match ($formSlug) {
            'po' => $this->cellValuesForAcquisitionPaperworkPo($paperwork),
            'iar' => $this->cellValuesForAcquisitionPaperworkIar($paperwork),
            default => $this->cellValuesForAcquisitionPaperworkPr($paperwork),
        };
        $formCode = strtoupper($formSlug === 'iar' ? 'IAR' : $formSlug);
        $docNumber = match ($formSlug) {
            'po' => $paperwork->po_number,
            'iar' => $paperwork->iar_number,
            default => $paperwork->pr_number,
        };
        $filename = $this->buildOwwaExportFilename($formCode, $docNumber ?? (string) $paperwork->getKey());

        return $this->downloadFromTemplate($templateFilename, $cellValues, $filename);
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForAcquisitionPaperworkPr(AcquisitionPaperwork $paperwork): array
    {
        return $this->cellValuesForProcurementPr($paperwork);
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForAcquisitionPaperworkPo(AcquisitionPaperwork $paperwork): array
    {
        return $this->cellValuesForProcurementPo($paperwork);
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForAcquisitionPaperworkIar(AcquisitionPaperwork $paperwork): array
    {
        return $this->cellValuesForProcurementIar($paperwork);
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForProcurementPr(AcquisitionPaperwork $case): array
    {
        $case->loadMissing(['office', 'department', 'lines.item']);
        $office = $case->office;
        $department = $case->department;
        $responsibilityCenterCode = $department?->code ?? $office?->code ?? '';
        $prMap = OwwaCellMapping::form('PR');
        $values = [];

        OwwaCellMapping::applyHeader($values, (array) ($prMap['header'] ?? []), [
            'entity_name' => $office?->name ?? '',
            'fund_cluster' => $office?->fund_cluster ?? '',
            'office_section' => $department?->name ?? $office?->name ?? '',
            'pr_no' => $this->ensureControlNumberFormat(
                $case->pr_number,
                $case->pr_date?->format('Y-m-d'),
                'yearly'
            ),
            'date' => $case->pr_date?->format('Y-m-d') ?? '',
            'responsibility_center_code' => $responsibilityCenterCode,
            'purpose' => $case->purpose ?? '',
        ]);

        $this->applyProcurementDetailRows($values, 'PR', $case->lines, [
            'stock_no' => fn ($line) => $line->stockNumber(),
            'unit' => fn ($line) => $line->unit ?? $line->item?->unit ?? '',
            'description' => fn ($line) => $line->description ?? $line->item?->name ?? '',
            'quantity' => fn ($line) => (string) $line->quantity,
            'unit_cost' => fn ($line) => $line->unit_cost !== null ? (string) $line->unit_cost : '',
            'total_cost' => fn ($line) => $line->amount !== null ? (string) $line->amount : '',
        ]);

        OwwaCellMapping::applySignatures($values, 'PR', [
            'requested_by' => $case->requested_by_name ?? '',
            'approved_by' => $case->approved_by_name ?? '',
        ]);

        return $values;
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForProcurementPo(AcquisitionPaperwork $case): array
    {
        $case->loadMissing(['office', 'lines.item']);
        $office = $case->office;
        $poData = (array) ($case->po_data ?? []);
        $poMap = OwwaCellMapping::form('PO');
        $values = [];

        OwwaCellMapping::applyHeader($values, (array) ($poMap['header'] ?? []), [
            'supplier' => $case->supplier ?? '',
            'po_no' => $this->ensureControlNumberFormat(
                $case->po_number,
                $case->po_date?->format('Y-m-d'),
                'yearly'
            ),
            'date' => $case->po_date?->format('Y-m-d') ?? '',
            'address' => $poData['address'] ?? '',
            'tin' => $poData['tin'] ?? '',
            'mode_of_procurement' => $poData['mode_of_procurement'] ?? '',
            'place_of_delivery' => $poData['place_of_delivery'] ?? '',
            'delivery_term' => $poData['delivery_term'] ?? '',
            'date_of_delivery' => $poData['date_of_delivery'] ?? '',
            'payment_term' => $poData['payment_term'] ?? '',
            'fund_cluster' => $office?->fund_cluster ?? '',
        ]);

        $this->applyProcurementDetailRows($values, 'PO', $case->lines, [
            'stock_no' => fn ($line) => $line->stockNumber(),
            'unit' => fn ($line) => $line->unit ?? $line->item?->unit ?? '',
            'description' => fn ($line) => $line->description ?? $line->item?->name ?? '',
            'quantity' => fn ($line) => (string) $line->quantity,
            'unit_cost' => fn ($line) => $line->unit_cost !== null ? (string) $line->unit_cost : '',
            'amount' => fn ($line) => $line->amount !== null ? (string) $line->amount : '',
        ]);

        return $values;
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForProcurementIar(AcquisitionPaperwork $case): array
    {
        $case->loadMissing(['office', 'department', 'lines.item']);
        $office = $case->office;
        $department = $case->department;
        $iarData = (array) ($case->iar_data ?? []);
        $responsibilityCenterCode = $department?->code ?? $office?->code ?? '';
        $poNoDate = trim(($case->po_number ?? '').' / '.($case->po_date?->format('Y-m-d') ?? ''), ' /');
        $iarMap = OwwaCellMapping::form('IAR');
        $values = [];

        OwwaCellMapping::applyHeader($values, (array) ($iarMap['header'] ?? []), [
            'entity_name' => $office?->name ?? '',
            'fund_cluster' => $office?->fund_cluster ?? '',
            'supplier' => $case->supplier ?? '',
            'iar_no' => $this->ensureControlNumberFormat(
                $case->iar_number,
                $case->iar_date?->format('Y-m-d'),
                'yearly'
            ),
            'po_no_date' => $poNoDate,
            'date' => $case->iar_date?->format('Y-m-d') ?? '',
            'requisitioning_office' => $department?->name ?? $office?->name ?? '',
            'invoice_no' => $iarData['invoice_no'] ?? '',
            'responsibility_center_code' => $responsibilityCenterCode,
            'invoice_date' => $iarData['invoice_date'] ?? '',
            'date_inspected' => $iarData['date_inspected'] ?? '',
            'date_received' => $iarData['date_received'] ?? '',
        ]);

        $this->applyProcurementDetailRows($values, 'IAR', $case->lines, [
            'stock_no' => fn ($line) => $line->stockNumber(),
            'description' => fn ($line) => $line->description ?? $line->item?->name ?? '',
            'unit' => fn ($line) => $line->unit ?? $line->item?->unit ?? '',
            'quantity' => fn ($line) => (string) $line->quantity,
        ]);

        OwwaCellMapping::applySignatures($values, 'IAR', [
            'inspection_officer' => $case->inspection_officer_name ?? '',
            'supply_custodian' => $case->custodian_name ?? '',
        ]);

        return $values;
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     * @param  \Illuminate\Support\Collection<int, \App\Models\AcquisitionPaperworkLine>|iterable<int, \App\Models\AcquisitionPaperworkLine>  $lines
     * @param  array<string, callable(\App\Models\AcquisitionPaperworkLine): string>  $columnResolvers
     */
    protected function applyProcurementDetailRows(array &$values, string $formCode, iterable $lines, array $columnResolvers): void
    {
        $detail = (array) OwwaCellMapping::form($formCode)['detail'];
        $startRow = (int) ($detail['start_row'] ?? 12);
        $maxRows = (int) ($detail['max_rows'] ?? 20);
        $columns = (array) ($detail['columns'] ?? []);
        $rowIndex = 0;

        foreach ($lines as $line) {
            if ($rowIndex >= $maxRows) {
                break;
            }

            $row = $startRow + $rowIndex;

            foreach ($columnResolvers as $columnKey => $resolver) {
                if (! isset($columns[$columnKey])) {
                    continue;
                }

                $values[OwwaCellMapping::columnCell($columns[$columnKey], $row)] = $resolver($line);
            }

            $rowIndex++;
        }
    }

    protected function ensureControlNumberFormat(?string $candidate, ?string $date, string $series): string
    {
        $normalized = strtoupper(trim((string) ($candidate ?? '')));
        if (preg_match('/^\d{4}-\d{2}-\d{4}$/', $normalized) === 1) {
            return $normalized;
        }

        $parsedDate = filled($date) ? \Illuminate\Support\Carbon::parse($date) : now();
        $year = $parsedDate->format('Y');
        $month = $parsedDate->format('m');

        preg_match_all('/\d+/', $normalized, $numericParts);
        $seed = implode('', $numericParts[0] ?? []);
        $serial = $seed !== '' ? ((int) substr($seed, -4)) : 0;
        if ($serial <= 0) {
            $serial = ((int) $parsedDate->format('dHis')) % 10000;
        }

        $serialPart = str_pad((string) $serial, 4, '0', STR_PAD_LEFT);
        $middle = $series === 'monthly' ? $month : '01';

        return "{$year}-{$middle}-{$serialPart}";
    }

    protected function formatItemDescription(?\App\Models\Item $item): string
    {
        if ($item === null) {
            return '';
        }

        $parts = array_filter([
            $item->name,
            $item->description,
            $item->serial_number ? 'S/N: '.$item->serial_number : null,
        ]);

        return implode(' — ', $parts);
    }
}
