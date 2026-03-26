<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\Issuance;
use App\Models\Requisition;
use App\Models\Transfer;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        int $sheetIndex = 0
    ): StreamedResponse
    {
        $path = $this->templatesPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $templateFilename);
        if (! is_readable($path)) {
            $altPath = preg_replace('/\.xlsx$/i', '.xls', $path);
            if ($altPath !== $path && is_readable($altPath)) {
                $path = $altPath;
            } else {
                throw new \InvalidArgumentException("OWWA template not found or not readable: {$templateFilename}. Place the file in storage/app/templates/ (e.g. consumables/, ppe/, semi_expendable/).");
            }
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet($sheetIndex);

        foreach ($cellValues as $cellRef => $value) {
            $sheet->setCellValue($cellRef, $value === null ? '' : $value);
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        return response()->streamDownload(
            function () use ($writer): void {
                $writer->save('php://output');
            },
            $outputFilename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
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
        $user = Auth::user();
        $isSupplyCustodian = $user?->isSupplyCustodian() ?? false;
        $fillAccountingFields = ! $isSupplyCustodian;

        $item = $issuance->item;
        $office = $issuance->office;
        $unitCost = $issuance->unit_cost !== null ? (float) $issuance->unit_cost : null;
        $amount = $issuance->amount !== null ? (float) $issuance->amount : null;

        return [
            'A6' => 'Entity Name: ' . ($office?->name ?? ''),
            'G6' => 'Serial No.: ' . ($issuance->reference_code ?? ''),
            'A7' => 'Fund Cluster: ' . ($office?->fund_cluster ?? $office?->name ?? ''),
            'G7' => 'Date: ' . ($issuance->issuance_date?->format('Y-m-d') ?? ''),
            'A12' => $issuance->reference_code ?? '',
            'B12' => $office?->name ?? $issuance->department?->name ?? '',
            'C12' => $item?->item_code ?? '',
            'D12' => $item?->name ?? '',
            'E12' => $item?->unit ?? '',
            'F12' => (string) $issuance->quantity,
            // Accounting-only fields (unit cost, amount) are filled only by Accounting staff.
            'G12' => $fillAccountingFields ? ($unitCost !== null ? $unitCost : '') : '',
            'H12' => $fillAccountingFields ? ($amount !== null ? $amount : '') : '',
            'B36' => $item?->item_code ?? '',
            'C36' => (string) $issuance->quantity,
            'F36' => $fillAccountingFields ? ($unitCost !== null ? $unitCost : '') : '',
            'G36' => $fillAccountingFields ? ($amount !== null ? $amount : '') : '',
            'A52' => $issuance->custodian_printed_name ?? '',
            'H52' => $issuance->issuance_date?->format('Y-m-d') ?? '',
        ];
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

        return [
            'A6' => 'Entity Name: ' . ($office?->name ?? ''),
            'A7' => 'Fund Cluster: ' . ($office?->fund_cluster ?? ''),
            'E7' => 'PAR No.: ' . ($issuance->reference_code ?? ''),
            'A11' => (string) $issuance->quantity,
            'B11' => $item?->unit ?? '',
            'C11' => $item?->name ?? '',
            'D11' => $issuance->property_number ?? $item?->item_code ?? '',
            'E11' => $dateAcquired ?? '',
            'F11' => $issuance->amount ?? $issuance->unit_cost ?? '',
            'A45' => $issuance->issuedTo?->name ?? $issuance->custodian_printed_name ?? '',
            'D45' => $issuance->custodian_printed_name ?? $issuance->issuedBy?->name ?? '',
            'A50' => $issuance->issuance_date?->format('Y-m-d') ?? '',
            'D50' => $issuance->issuance_date?->format('Y-m-d') ?? '',
        ];
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

        return [
            'A6' => 'Entity Name: ' . ($office?->name ?? ''),
            'A7' => 'Fund Cluster: ' . ($office?->fund_cluster ?? ''),
            'G7' => 'ICS No.: ' . ($issuance->reference_code ?? ''),
            'A12' => (string) $issuance->quantity,
            'B12' => $item?->unit ?? '',
            'C12' => $unitCost !== null ? $unitCost : '',
            'D12' => $totalCost !== null ? $totalCost : '',
            'E12' => $item?->name ?? '',
            'G12' => $issuance->property_number ?? $item?->item_code ?? '',
            'A46' => $issuance->issuedTo?->name ?? '',
            'F46' => $issuance->custodian_printed_name ?? $issuance->issuedBy?->name ?? '',
            'A51' => $issuance->issuance_date?->format('Y-m-d') ?? '',
            'F51' => $issuance->issuance_date?->format('Y-m-d') ?? '',
        ];
    }

    /**
     * Build cell values for a single Transfer record. Uses template-specific mapping for PTR (Appendix 76).
     *
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForTransfer(Transfer $transfer, ?string $templatePath = null): array
    {
        $transfer->load(['item', 'fromOffice', 'toOffice', 'recordedBy']);
        if ($templatePath !== null && (str_contains($templatePath, 'PTR') || str_contains($templatePath, 'Appendix 76'))) {
            return $this->cellValuesForTransferPtr($transfer);
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

        return [
            'A6' => 'Entity Name: ' . ($from?->name ?? $to?->name ?? ''),
            'G6' => 'Fund Cluster: ' . ($from?->fund_cluster ?? $to?->fund_cluster ?? ''),
            'A8' => 'From Accountable Officer/Agency/Fund Cluster: ' . ($from?->name ?? ''),
            'H8' => 'PTR No.: ' . ($transfer->reference_code ?? ''),
            'A9' => 'To Accountable Officer/Agency/Fund Cluster: ' . ($to?->name ?? ''),
            'H9' => 'Date: ' . ($transfer->transfer_date?->format('Y-m-d') ?? ''),
            'A18' => $dateAcquired ?? '',
            'B18' => $transfer->property_number ?? $item?->item_code ?? '',
            'D18' => $item?->name,
            'H18' => $acquisitionCost !== null ? $acquisitionCost : '',
            'I18' => $transfer->condition ?? '',
            'B53' => $transfer->approved_by_printed_name ?? $from?->name ?? '',
            'F53' => $transfer->released_by_printed_name ?? $transfer->recordedBy?->name ?? '',
            'H53' => $transfer->received_by_printed_name ?? $to?->name ?? '',
            'B55' => $transfer->transfer_date?->format('Y-m-d'),
            'F55' => $transfer->transfer_date?->format('Y-m-d'),
            'H55' => $transfer->transfer_date?->format('Y-m-d'),
        ];
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
        $item = $disposal->item;
        $office = $disposal->office;

        return [
            'A7' => 'Entity Name: ' . ($office?->name ?? ''),
            'G7' => 'Fund Cluster: ' . ($office?->fund_cluster ?? $office?->name ?? ''),
            'A8' => 'Place of Storage: ' . ($office?->name ?? ''),
            'G8' => 'Date: ' . ($disposal->disposal_date?->format('Y-m-d') ?? ''),
            'A13' => 1,
            'B13' => (string) $disposal->quantity,
            'C13' => $item?->unit ?? '',
            'D13' => $item?->name . ($disposal->reason ? ' – ' . $disposal->reason : ''),
            'G13' => $disposal->official_receipt_no ?? '',
            'H13' => $disposal->sale_date?->format('Y-m-d') ?? '',
            'I13' => $disposal->sale_amount !== null ? (float) $disposal->sale_amount : '',
            'B25' => $disposal->custodian_printed_name ?? '',
            'G25' => $disposal->approved_by_printed_name ?? '',
            'B37' => $disposal->inspection_officer_printed_name ?? '',
            'G37' => $disposal->witness_printed_name ?? '',
        ];
    }

    /**
     * Cell mapping for Appendix 74 - IIRUP (Unserviceable Property).
     * Headers span merged rows 11-13; column numbers in row 14; first data row = 15.
     *
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForDisposalIirup(Disposal $disposal): array
    {
        $item = $disposal->item;
        $office = $disposal->office;
        $dateAcquired = $this->lookupDateAcquired($disposal->item_id);

        return [
            'B6' => 'Entity Name: ' . ($office?->name ?? ''),
            'P6' => 'Fund Cluster: ' . ($office?->fund_cluster ?? ''),
            'B15' => $dateAcquired ?? '',
            'C15' => $item?->name,
            'D15' => $disposal->property_number ?? $item?->item_code ?? '',
            'E15' => (string) $disposal->quantity,
            'K15' => $disposal->reason ?? '',
            'C40' => $disposal->custodian_printed_name ?? '',
            'H40' => $disposal->approved_by_printed_name ?? '',
            'L40' => $disposal->inspection_officer_printed_name ?? '',
            'Q40' => $disposal->witness_printed_name ?? '',
        ];
    }

    /**
     * Cell mapping for Annex A.10 - IIRUSP (Unserviceable Semi-Expendable).
     * Headers span merged rows 14-16; column numbers in row 17; first data row = 18.
     *
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForDisposalIirusp(Disposal $disposal): array
    {
        $item = $disposal->item;
        $office = $disposal->office;
        $dateAcquired = $this->lookupDateAcquired($disposal->item_id);

        return [
            'B9' => 'Entity Name: ' . ($office?->name ?? ''),
            'P9' => 'Fund Cluster: ' . ($office?->fund_cluster ?? ''),
            'B18' => $dateAcquired ?? '',
            'C18' => $item?->name,
            'D18' => $disposal->property_number ?? $item?->item_code ?? '',
            'E18' => (string) $disposal->quantity,
            'K18' => $disposal->reason ?? '',
            'C37' => $disposal->custodian_printed_name ?? '',
            'H37' => $disposal->approved_by_printed_name ?? '',
            'L38' => $disposal->inspection_officer_printed_name ?? '',
            'Q38' => $disposal->witness_printed_name ?? '',
        ];
    }

    /**
     * Cell mapping for Appendix 75 - RLSDDP (Lost/Stolen/Damaged/Destroyed).
     *
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForDisposalRlsddp(Disposal $disposal): array
    {
        $item = $disposal->item;
        $office = $disposal->office;

        return [
            'B6' => 'Entity Name: ' . ($office?->name ?? ''),
            'G6' => 'Fund Cluster: ' . ($office?->fund_cluster ?? ''),
            'B8' => 'Department/Office: ' . ($office?->name ?? ''),
            'G8' => 'RLSDDP No.: ' . ($disposal->reference_code ?? ''),
            'B9' => 'Accountable Officer: ' . ($disposal->custodian_printed_name ?? ''),
            'G9' => 'RLSDDP Date: ' . ($disposal->disposal_date?->format('Y-m-d') ?? ''),
            'B20' => $disposal->property_number ?? $item?->item_code ?? '',
            'C20' => $item?->name . ($disposal->reason ? ' – ' . $disposal->reason : ''),
            'G20' => $disposal->acquisition_cost !== null ? (float) $disposal->acquisition_cost : '',
            'B30' => $disposal->reason ?? '',
            'B39' => $disposal->custodian_printed_name ?? '',
            'F39' => $disposal->approved_by_printed_name ?? '',
            'B41' => $disposal->disposal_date?->format('Y-m-d'),
            'F41' => $disposal->disposal_date?->format('Y-m-d'),
        ];
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

        $values = [
            // Header block
            'A6' => 'Entity Name: ' . ($office?->name ?? ''),
            'E6' => 'Fund Cluster: ' . ($office?->fund_cluster ?? ''),
            'A7' => 'Division: ' . ($department?->name ?? ''),
            'E7' => 'Office: ' . ($office?->name ?? ''),
            'G6' => 'RIS No.: ' . ($requisition->reference_code ?? ''),
            'G7' => 'Date: ' . ($requisition->created_at?->format('Y-m-d') ?? ''),
            // Purpose / remarks on the requisition
            'A10' => $requisition->remarks ?? '',
        ];

        // Detail table – first data row on standard Appendix 63 is typically row 13 or 14.
        // We start at row 13 here; adjust the template if rows differ.
        $startRow = 13;
        $maxRows = 15;
        $rowIndex = 0;

        foreach ($requisition->items as $itemLine) {
            if ($rowIndex >= $maxRows) {
                break;
            }

            $row = $startRow + $rowIndex;
            $item = $itemLine->item;

            $values['A' . $row] = $item?->item_code ?? '';                    // Stock No.
            $values['B' . $row] = $item?->unit ?? '';                         // Unit
            $values['C' . $row] = $item?->name ?? '';                         // Description
            $values['D' . $row] = (string) $itemLine->quantity;              // Quantity (Requisition)
            // Columns for stock availability, quantity issued, and remarks are left for supply/accounting.

            $rowIndex++;
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
        $slug = $category?->getTemplateSlug() ?? 'consumables';
        $formKey = ($formSlug === null || $formSlug === '') ? 'default' : $formSlug;

        $fromConfig = config("owwa_templates.{$transactionType}.{$slug}.{$formKey}", null);
        if (is_array($fromConfig) && ! empty($fromConfig['file'])) {
            return $slug . '/' . $fromConfig['file'];
        }

        if ($formSlug !== null && $formSlug !== '' && $formSlug !== 'default') {
            return $slug . '/' . $formSlug . '.xlsx';
        }

        return $slug . '/' . $slug . '.xlsx';
    }

    /**
     * List available forms for a category (from config with OWWA labels, or from directory scan).
     *
     * @return array<string, string> Map of form_slug => label (e.g. ['' => 'Default', 'sc' => 'Appendix 58 - SC'])
     */
    public function getAvailableFormsForCategory(string $transactionType, ?\App\Models\ItemCategory $category): array
    {
        $slug = $category?->getTemplateSlug() ?? 'consumables';
        $configForms = config("owwa_templates.{$transactionType}.{$slug}", null);
        if (is_array($configForms)) {
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

        $dir = $this->templatesPath . DIRECTORY_SEPARATOR . $slug;
        $forms = [];
        if (! is_dir($dir)) {
            return ['' => 'Default'];
        }
        $files = array_merge(
            glob($dir . DIRECTORY_SEPARATOR . '*.xlsx') ?: [],
            glob($dir . DIRECTORY_SEPARATOR . '*.xls') ?: []
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
            $dir = $this->templatesPath . DIRECTORY_SEPARATOR . $cat;
            if (! is_dir($dir)) {
                continue;
            }
            $files = array_merge(
                glob($dir . DIRECTORY_SEPARATOR . '*.xlsx') ?: [],
                glob($dir . DIRECTORY_SEPARATOR . '*.xls') ?: []
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
        if ($templateFilename === null) {
            $templateFilename = $this->getTemplatePathForCategory('issuance', $issuance->item?->category, $formSlug);
        }
        $filename = 'owwa_issuance_' . ($issuance->reference_code ?? 'export') . '.xlsx';

        return $this->downloadFromTemplate(
            $templateFilename,
            $this->cellValuesForIssuance($issuance, $templateFilename),
            $filename
        );
    }

    /**
     * Export a single transfer as OWWA-format Excel using the template for the item's category and optional form.
     */
    public function downloadTransfer(Transfer $transfer, ?string $templateFilename = null, ?string $formSlug = null): StreamedResponse
    {
        $transfer->loadMissing('item.category');
        if ($templateFilename === null) {
            $templateFilename = $this->getTemplatePathForCategory('transfer', $transfer->item?->category, $formSlug);
        }
        $filename = 'owwa_transfer_' . ($transfer->reference_code ?? 'export') . '.xlsx';

        return $this->downloadFromTemplate(
            $templateFilename,
            $this->cellValuesForTransfer($transfer, $templateFilename),
            $filename
        );
    }

    /**
     * Export a single disposal as OWWA-format Excel using the template for the item's category and optional form.
     */
    public function downloadDisposal(Disposal $disposal, ?string $templateFilename = null, ?string $formSlug = null): StreamedResponse
    {
        $disposal->loadMissing('item.category');
        if ($disposal->disposal_type === 'lost_stolen_damaged') {
            $formSlug = $formSlug ?? 'rlsddp';
        }
        if ($templateFilename === null) {
            $templateFilename = $this->getTemplatePathForCategory('disposal', $disposal->item?->category, $formSlug);
        }
        $filename = 'owwa_disposal_' . ($disposal->reference_code ?? 'export') . '.xlsx';

        return $this->downloadFromTemplate(
            $templateFilename,
            $this->cellValuesForDisposal($disposal, $templateFilename),
            $filename
        );
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
            $templateFilename = 'requisition/Appendix 63 - RIS.xlsx';
        }

        $filename = 'owwa_requisition_' . ($requisition->reference_code ?? 'export') . '.xlsx';

        return $this->downloadFromTemplate(
            $templateFilename,
            $this->cellValuesForRequisition($requisition),
            $filename
        );
    }
}
