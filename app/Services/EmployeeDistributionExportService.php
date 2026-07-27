<?php

namespace App\Services;

use App\Models\Distribution;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Support\OwwaExportFilename;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDistributionExportService
{
    public function download(
        User $employee,
        string $category,
        string $custodyTab,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?int $itemId = null,
    ): StreamedResponse {
        $rows = $this->detailRows($employee, $category, $custodyTab, $fromDate, $toDate, $itemId);
        $spreadsheet = $this->buildWorkbook($employee, $rows, $fromDate, $toDate, $itemId);
        $writer = new Xlsx($spreadsheet);
        $filename = 'Employee-Distribution-'.OwwaExportFilename::sanitizeSegment($employee->name).'-'.now()->format('Y-m-d_His').'.xlsx';

        return response()->streamDownload(function () use ($writer, $spreadsheet): void {
            try {
                $writer->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return Collection<int, array{
     *     employee: string,
     *     item: string,
     *     category: string,
     *     date: string,
     *     ris_no: string,
     *     qty: int,
     *     running_total: int,
     *     purpose: string,
     *     requisition_txn: string
     * }>
     */
    protected function detailRows(
        User $employee,
        string $category,
        string $custodyTab,
        ?string $fromDate,
        ?string $toDate,
        ?int $itemId,
    ): Collection {
        if (EmployeeDistributionInventoryService::usesPropertyIssuanceView($category)) {
            return $this->propertyRows($employee, $category, $custodyTab, $fromDate, $toDate, $itemId);
        }

        return $this->distributionRows($employee, $fromDate, $toDate, $itemId);
    }

    /**
     * @return Collection<int, array<string, string|int>>
     */
    protected function distributionRows(User $employee, ?string $fromDate, ?string $toDate, ?int $itemId): Collection
    {
        $query = Distribution::query()
            ->with([
                'distributedTo',
                'item.category',
                'requisition.compiledIntoRequisition',
                'requisition.requestedBy',
                'requisition.sourceRequests.items',
                'requisitionItem.requisition',
            ])
            ->where('distributed_to', $employee->id)
            ->whereIn('item_id', $this->itemIdsForCategorySlug(EmployeeDistributionInventoryService::CATEGORY_CONSUMABLES));

        if ($itemId !== null && $itemId > 0) {
            $query->where('item_id', $itemId);
        }

        $this->applyDatePeriodFilter($query, 'distribution_date', $fromDate, $toDate);

        $runningTotals = [];

        return $query
            ->orderBy('distribution_date')
            ->orderBy('id')
            ->get()
            ->map(function (Distribution $distribution) use (&$runningTotals, $employee): array {
                $itemKey = (int) $distribution->item_id;
                $runningTotals[$itemKey] = ($runningTotals[$itemKey] ?? 0) + (int) $distribution->quantity;
                $requisition = $distribution->requisition;

                return [
                    'item_id' => $itemKey,
                    'employee' => $distribution->distributedTo?->name ?? $employee->name,
                    'item' => $distribution->item?->name ?? '—',
                    'category' => $distribution->item?->category?->name ?? '—',
                    'date' => $distribution->distribution_date?->format('M j, Y') ?? '—',
                    'ris_no' => $requisition?->displayRisNumber() ?? $requisition?->reference_code ?? '—',
                    'qty' => (int) $distribution->quantity,
                    'running_total' => (int) $runningTotals[$itemKey],
                    'purpose' => $requisition?->displayRisPurpose() ?? '—',
                    'requisition_txn' => $this->resolveEmployeeTransactionNumber(
                        $requisition,
                        $employee,
                        $itemKey,
                        $distribution->requisition_item_id !== null ? (int) $distribution->requisition_item_id : null,
                    ),
                ];
            });
    }

    /**
     * @return Collection<int, array<string, string|int>>
     */
    protected function propertyRows(
        User $employee,
        string $category,
        string $custodyTab,
        ?string $fromDate,
        ?string $toDate,
        ?int $itemId,
    ): Collection {
        $slug = $category === EmployeeDistributionInventoryService::CATEGORY_PPE
            ? EmployeeDistributionInventoryService::CATEGORY_PPE
            : EmployeeDistributionInventoryService::CATEGORY_SEMI_EXPENDABLE;

        $query = Issuance::query()
            ->with([
                'issuedTo',
                'item.category',
                'requisition.compiledIntoRequisition',
                'requisition.requestedBy',
                'requisition.sourceRequests.items',
            ])
            ->where('issued_to', $employee->id)
            ->whereIn('item_id', $this->itemIdsForCategorySlug($slug));

        if ($itemId !== null && $itemId > 0) {
            $query->where('item_id', $itemId);
        }

        $this->applyPropertyCustodyTabFilter($query, $custodyTab);
        $this->applyDatePeriodFilter($query, 'issuance_date', $fromDate, $toDate);

        $runningTotals = [];

        return $query
            ->orderBy('issuance_date')
            ->orderBy('id')
            ->get()
            ->map(function (Issuance $issuance) use (&$runningTotals, $employee): array {
                $itemKey = (int) $issuance->item_id;
                $runningTotals[$itemKey] = ($runningTotals[$itemKey] ?? 0) + (int) $issuance->quantity;
                $requisition = $issuance->requisition;

                return [
                    'item_id' => $itemKey,
                    'employee' => $issuance->issuedTo?->name ?? $employee->name,
                    'item' => $issuance->item?->name ?? '—',
                    'category' => $issuance->item?->category?->name ?? '—',
                    'date' => $issuance->issuance_date?->format('M j, Y') ?? '—',
                    'ris_no' => $requisition?->displayRisNumber() ?? $requisition?->reference_code ?? '—',
                    'qty' => (int) $issuance->quantity,
                    'running_total' => (int) $runningTotals[$itemKey],
                    'purpose' => $requisition?->displayRisPurpose() ?? '—',
                    'requisition_txn' => $this->resolveEmployeeTransactionNumber(
                        $requisition,
                        $employee,
                        $itemKey,
                    ),
                ];
            });
    }

    /**
     * @param  Collection<int, array<string, string|int>>  $rows
     */
    protected function buildWorkbook(User $employee, Collection $rows, ?string $fromDate, ?string $toDate, ?int $itemId): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

        if ($itemId !== null && $itemId > 0) {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle($this->sheetTitleForRows($rows, 'Item'));
            $this->writeHeaderBlock($sheet, $employee, $fromDate, $toDate, $itemId);
            $this->writeDetailRows($sheet, $rows);

            return $spreadsheet;
        }

        $grouped = $rows->groupBy(fn (array $row): int => (int) ($row['item_id'] ?? 0));

        if ($grouped->isEmpty()) {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('No data');
            $this->writeHeaderBlock($sheet, $employee, $fromDate, $toDate, null);
            $this->writeDetailRows($sheet, collect());

            return $spreadsheet;
        }

        $first = true;
        $usedTitles = [];

        foreach ($grouped as $groupRows) {
            /** @var Collection<int, array<string, string|int>> $groupRows */
            $title = $this->uniqueSheetTitle(
                $this->sheetTitleForRows($groupRows, 'Item'),
                $usedTitles,
            );
            $usedTitles[] = strtolower($title);

            $sheet = $first
                ? $spreadsheet->getActiveSheet()
                : $spreadsheet->createSheet();
            $first = false;

            $sheet->setTitle($title);
            $this->writeHeaderBlock($sheet, $employee, $fromDate, $toDate, null, (string) ($groupRows->first()['item'] ?? 'Item'));
            $this->writeDetailRows($sheet, $groupRows->values());
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    protected function writeHeaderBlock(
        Worksheet $sheet,
        User $employee,
        ?string $fromDate,
        ?string $toDate,
        ?int $itemId,
        ?string $itemLabel = null,
    ): void {
        $sheet->mergeCells('A1:I1');
        $sheet->setCellValue('A1', 'Employee Distribution History');
        $sheet->setCellValue('A2', 'Employee');
        $sheet->setCellValue('B2', $employee->name);
        $sheet->setCellValue('A3', 'Period covered');
        $sheet->setCellValue('B3', $this->periodLabel($fromDate, $toDate));
        $sheet->setCellValue('A4', 'Generated date');
        $sheet->setCellValue('B4', now()->format('M j, Y g:i A'));
        $sheet->setCellValue('D2', 'Scope');
        $sheet->setCellValue(
            'E2',
            $itemId !== null && $itemId > 0
                ? 'Selected item'
                : ($itemLabel !== null ? 'Item sheet: '.$itemLabel : 'All items (one tab per item)'),
        );

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2:A4')->getFont()->setBold(true);
        $sheet->getStyle('D2')->getFont()->setBold(true);
    }

    /**
     * @param  Collection<int, array<string, string|int>>  $rows
     */
    protected function writeDetailRows(Worksheet $sheet, Collection $rows): void
    {
        $headers = [
            'Employee',
            'Item',
            'Category',
            'Date',
            'RIS No.',
            'Qty',
            'Running Total',
            'Purpose',
            'Transaction No.',
        ];

        $headerRow = 6;
        foreach ($headers as $index => $label) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$headerRow, $label);
        }

        $rowNumber = $headerRow + 1;
        foreach ($rows as $row) {
            $sheet->fromArray([
                $row['employee'],
                $row['item'],
                $row['category'],
                $row['date'],
                $row['ris_no'],
                $row['qty'],
                $row['running_total'],
                $row['purpose'],
                $row['requisition_txn'],
            ], null, 'A'.$rowNumber);
            $rowNumber++;
        }

        if ($rows->isEmpty()) {
            $sheet->mergeCells('A7:I7');
            $sheet->setCellValue('A7', 'No distribution or issuance events matched the selected period.');
            $rowNumber = 8;
        }

        $lastDataRow = max($headerRow, $rowNumber - 1);
        $sheet->freezePane('A7');
        $sheet->getStyle('A'.$headerRow.':I'.$headerRow)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle('A'.$headerRow.':I'.$lastDataRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('F7:G'.$lastDataRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    protected function resolveEmployeeTransactionNumber(
        ?Requisition $requisition,
        User $employee,
        int $itemId,
        ?int $requisitionItemId = null,
    ): string {
        if ($requisitionItemId !== null && $requisitionItemId > 0) {
            $line = RequisitionItem::query()->with('requisition')->find($requisitionItemId);
            $fromLine = $line?->requisition?->displayTransactionNumber();

            if (filled($fromLine)) {
                return $fromLine;
            }
        }

        if ($requisition === null) {
            return '—';
        }

        if ($requisition->isEmployeeRequest()) {
            return $requisition->displayTransactionNumber() ?? '—';
        }

        $requisition->loadMissing(['sourceRequests.items']);

        $matching = $requisition->sourceRequests
            ->filter(function (Requisition $source) use ($employee, $itemId): bool {
                if ((int) $source->requested_by !== (int) $employee->id) {
                    return false;
                }

                return $source->items->contains(
                    fn (RequisitionItem $item): bool => (int) $item->item_id === $itemId,
                );
            })
            ->map(fn (Requisition $source): ?string => $source->displayTransactionNumber())
            ->filter(fn (?string $txn): bool => filled($txn))
            ->unique()
            ->values();

        if ($matching->isNotEmpty()) {
            return $matching->implode(', ');
        }

        $anyForEmployee = $requisition->sourceRequests
            ->filter(fn (Requisition $source): bool => (int) $source->requested_by === (int) $employee->id)
            ->map(fn (Requisition $source): ?string => $source->displayTransactionNumber())
            ->filter(fn (?string $txn): bool => filled($txn))
            ->unique()
            ->values();

        return $anyForEmployee->isNotEmpty() ? $anyForEmployee->implode(', ') : '—';
    }

    protected function periodLabel(?string $fromDate, ?string $toDate): string
    {
        $from = $this->parseDateFilter($fromDate)?->format('M j, Y');
        $to = $this->parseDateFilter($toDate)?->format('M j, Y');

        return match (true) {
            $from !== null && $to !== null => $from.' to '.$to,
            $from !== null => 'From '.$from,
            $to !== null => 'Through '.$to,
            default => 'All dates',
        };
    }

    /**
     * @param  Collection<int, array<string, string|int>>  $rows
     */
    protected function sheetTitleForRows(Collection $rows, string $fallback): string
    {
        $name = (string) ($rows->first()['item'] ?? $fallback);

        return $this->sanitizeSheetTitle($name);
    }

    /**
     * @param  list<string>  $usedTitlesLower
     */
    protected function uniqueSheetTitle(string $title, array $usedTitlesLower): string
    {
        $base = $this->sanitizeSheetTitle($title);
        $candidate = $base;
        $suffix = 2;

        while (in_array(strtolower($candidate), $usedTitlesLower, true)) {
            $trimmed = mb_substr($base, 0, max(1, 31 - strlen('_'.$suffix)));
            $candidate = $trimmed.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    protected function sanitizeSheetTitle(string $title): string
    {
        $clean = str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $title);
        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));

        if ($clean === '') {
            $clean = 'Item';
        }

        return mb_substr($clean, 0, 31);
    }

    protected function applyDatePeriodFilter(Builder $query, string $column, ?string $fromDate, ?string $toDate): void
    {
        $from = $this->parseDateFilter($fromDate)?->startOfDay();
        $to = $this->parseDateFilter($toDate)?->endOfDay();

        if ($from !== null) {
            $query->where($column, '>=', $from);
        }

        if ($to !== null) {
            $query->where($column, '<=', $to);
        }
    }

    protected function applyPropertyCustodyTabFilter(Builder $query, string $custodyTab): void
    {
        if ($custodyTab === EmployeeDistributionInventoryService::CUSTODY_TAB_HISTORY) {
            $query->whereNotNull('custody_ended_at');

            return;
        }

        $query
            ->whereNull('custody_ended_at')
            ->where(function (Builder $scope): void {
                $scope
                    ->whereDoesntHave('inventoryUnit')
                    ->orWhereHas('inventoryUnit', fn (Builder $unitQuery): Builder => $unitQuery
                        ->where('status', InventoryUnit::STATUS_ISSUED));
            });
    }

    protected function parseDateFilter(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return Collection<int, int>
     */
    protected function itemIdsForCategorySlug(string $slug): Collection
    {
        return ItemCategory::query()
            ->get()
            ->filter(fn (ItemCategory $category): bool => $category->getTemplateSlug() === $slug)
            ->pluck('id')
            ->pipe(fn (Collection $categoryIds): Collection => Item::query()
                ->whereIn('item_category_id', $categoryIds)
                ->pluck('id'))
            ->values();
    }
}
