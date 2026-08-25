<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Models\StockOpeningBalance;
use App\Models\Transfer;
use App\Support\AnnexA1BlockLayout;
use App\Support\AnnexA4Layout;
use App\Support\ConsumableInventoryType;
use App\Support\IssuanceDistributionVisibility;
use App\Support\ItemPropertyClass;
use App\Support\OwwaCellMapping;
use App\Support\OwwaExportDiagnostics;
use App\Support\OwwaExportFilename;
use App\Support\PhysicalCountPageLayout;
use App\Support\PhysicalCountPropertyClassResolver;
use App\Support\PpePropertyType;
use App\Support\PropertyCardLayout;
use App\Support\UnitCostKey;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OwwaItemReportService
{
    public function __construct(
        protected OwwaTemplateExportService $templateExport,
        protected InventoryStockService $stockService,
    ) {}

    /**
     * Merged ledger lines for item-level cards (Stock Card, Property Card, etc.).
     *
     * Sources: acquisitions (receipt), issuances (issue), transfers in/out, disposals (issue).
     * Optional office filter limits rows to that office.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildTransactionHistory(
        Item $item,
        ?int $officeId = null,
        bool $newestFirst = false,
        ?float $unitCost = null,
    ): array {
        $histories = $this->buildTransactionHistoriesForItems(
            [$item->id],
            $officeId,
            $newestFirst,
            collect([$item->id => $item]),
            $unitCost,
        );

        return $histories[$item->id] ?? [];
    }

    /**
     * @param  array<int, int>  $itemIds
     * @param  int|array<int, int>|null  $officeIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function buildTransactionHistoriesForItems(
        array $itemIds,
        int|array|null $officeIds = null,
        bool $newestFirst = false,
        ?Collection $itemsById = null,
        ?float $unitCost = null,
    ): array {
        $itemIds = array_values(array_unique(array_filter($itemIds, fn (int $id): bool => $id > 0)));

        if ($itemIds === []) {
            return [];
        }

        $normalizedOfficeIds = match (true) {
            $officeIds === null => [],
            is_int($officeIds) => $officeIds > 0 ? [$officeIds] : [],
            default => array_values(array_unique(array_filter($officeIds, fn (int $id): bool => $id > 0))),
        };

        $itemsById ??= Item::query()->whereIn('id', $itemIds)->get()->keyBy('id');

        /** @var array<int, array<int, array<string, mixed>>> $rowsByItem */
        $rowsByItem = array_fill_keys($itemIds, []);

        Acquisition::query()
            ->with('office')
            ->whereIn('item_id', $itemIds)
            ->when($normalizedOfficeIds !== [], fn ($q) => $q->whereIn('office_id', $normalizedOfficeIds))
            ->orderBy('acquisition_date')
            ->get()
            ->each(function (Acquisition $acquisition) use (&$rowsByItem, $itemsById, $unitCost): void {
                if ($unitCost !== null && ! UnitCostKey::equals(
                    $acquisition->unit_cost !== null ? (float) $acquisition->unit_cost : null,
                    $unitCost,
                )) {
                    return;
                }

                $item = $itemsById->get($acquisition->item_id);

                $rowsByItem[$acquisition->item_id][] = [
                    'office_id' => $acquisition->office_id,
                    'sort_date' => $acquisition->acquisition_date,
                    'date' => $acquisition->acquisition_date?->format('Y-m-d'),
                    'reference' => $acquisition->reference_code,
                    'type' => 'receipt',
                    'receipt_qty' => $acquisition->quantity,
                    'issue_qty' => null,
                    'issue_office' => null,
                    'office_officer' => $acquisition->office?->name,
                    'remarks' => $acquisition->remarks,
                    'property_number' => null,
                    'unit_cost' => $acquisition->unit_cost,
                    'item_code' => $item?->item_code,
                ];
            });

        Issuance::query()
            ->with(['office', 'issuedTo'])
            ->whereIn('item_id', $itemIds)
            ->when($normalizedOfficeIds !== [], fn ($q) => $q->whereIn('office_id', $normalizedOfficeIds))
            ->orderBy('issuance_date')
            ->get()
            ->each(function (Issuance $issuance) use (&$rowsByItem, $unitCost): void {
                if ($unitCost !== null && ! UnitCostKey::equals(
                    $issuance->unit_cost !== null ? (float) $issuance->unit_cost : null,
                    $unitCost,
                )) {
                    return;
                }

                $rowsByItem[$issuance->item_id][] = [
                    'office_id' => $issuance->office_id,
                    'sort_date' => $issuance->issuance_date,
                    'date' => $issuance->issuance_date?->format('Y-m-d'),
                    'reference' => $issuance->controlNumber(),
                    'type' => 'issue',
                    'receipt_qty' => null,
                    'issue_qty' => $issuance->quantity,
                    'issue_office' => $issuance->office?->name,
                    'office_officer' => IssuanceDistributionVisibility::holderLabelForIssuance($issuance)
                        ?? $issuance->issuedTo?->name
                        ?? $issuance->office?->name,
                    'remarks' => $issuance->remarks,
                    'property_number' => $issuance->property_number,
                    'unit_cost' => $issuance->unit_cost,
                ];
            });

        Transfer::query()
            ->with(['fromOffice', 'toOffice'])
            ->whereIn('item_id', $itemIds)
            ->when($normalizedOfficeIds !== [], fn ($q) => $q->whereIn('to_office_id', $normalizedOfficeIds))
            ->orderBy('transfer_date')
            ->get()
            ->each(function (Transfer $transfer) use (&$rowsByItem, $unitCost): void {
                if ($unitCost !== null && ! UnitCostKey::equals(
                    $transfer->unit_cost !== null ? (float) $transfer->unit_cost : null,
                    $unitCost,
                )) {
                    return;
                }

                $rowsByItem[$transfer->item_id][] = [
                    'office_id' => $transfer->to_office_id,
                    'sort_date' => $transfer->transfer_date,
                    'date' => $transfer->transfer_date?->format('Y-m-d'),
                    'reference' => $transfer->reference_code,
                    'type' => 'transfer_in',
                    'receipt_qty' => $transfer->quantity,
                    'issue_qty' => null,
                    'issue_office' => $transfer->fromOffice?->name,
                    'office_officer' => $transfer->to_accountable_officer ?? $transfer->toOffice?->name,
                    'remarks' => $transfer->remarks,
                    'property_number' => $transfer->property_number,
                    'unit_cost' => $transfer->unit_cost,
                ];
            });

        Transfer::query()
            ->with(['fromOffice', 'toOffice'])
            ->whereIn('item_id', $itemIds)
            ->when($normalizedOfficeIds !== [], fn ($q) => $q->whereIn('from_office_id', $normalizedOfficeIds))
            ->orderBy('transfer_date')
            ->get()
            ->each(function (Transfer $transfer) use (&$rowsByItem, $unitCost): void {
                if ($unitCost !== null && ! UnitCostKey::equals(
                    $transfer->unit_cost !== null ? (float) $transfer->unit_cost : null,
                    $unitCost,
                )) {
                    return;
                }

                $rowsByItem[$transfer->item_id][] = [
                    'office_id' => $transfer->from_office_id,
                    'sort_date' => $transfer->transfer_date,
                    'date' => $transfer->transfer_date?->format('Y-m-d'),
                    'reference' => $transfer->reference_code,
                    'type' => 'transfer_out',
                    'receipt_qty' => null,
                    'issue_qty' => $transfer->quantity,
                    'issue_office' => $transfer->toOffice?->name,
                    'office_officer' => $transfer->from_accountable_officer ?? $transfer->fromOffice?->name,
                    'remarks' => $transfer->remarks,
                    'property_number' => $transfer->property_number,
                    'unit_cost' => $transfer->unit_cost,
                ];
            });

        Disposal::query()
            ->with('office')
            ->whereIn('item_id', $itemIds)
            ->when($normalizedOfficeIds !== [], fn ($q) => $q->whereIn('office_id', $normalizedOfficeIds))
            ->orderBy('disposal_date')
            ->get()
            ->each(function (Disposal $disposal) use (&$rowsByItem, $unitCost): void {
                if ($unitCost !== null && ! UnitCostKey::equals(
                    $disposal->acquisition_cost !== null ? (float) $disposal->acquisition_cost : null,
                    $unitCost,
                )) {
                    return;
                }

                $rowsByItem[$disposal->item_id][] = [
                    'office_id' => $disposal->office_id,
                    'sort_date' => $disposal->disposal_date,
                    'date' => $disposal->disposal_date?->format('Y-m-d'),
                    'reference' => $disposal->reference_code,
                    'type' => 'disposal',
                    'receipt_qty' => null,
                    'issue_qty' => $disposal->quantity,
                    'issue_office' => $disposal->office?->name,
                    'office_officer' => $disposal->office?->name,
                    'remarks' => $disposal->reason,
                    'property_number' => $disposal->property_number,
                    'unit_cost' => $disposal->acquisition_cost,
                ];
            });

        foreach ($itemIds as $itemId) {
            $rowsByItem[$itemId] = $this->finalizeTransactionHistoryRows(
                $rowsByItem[$itemId],
                $newestFirst,
                (int) $itemId,
                $unitCost,
            );
        }

        return $rowsByItem;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function finalizeTransactionHistoryRows(
        array $rows,
        bool $newestFirst,
        int $itemId,
        ?float $unitCost = null,
    ): array {
        $grouped = [];
        foreach ($rows as $row) {
            $officeId = (int) ($row['office_id'] ?? 0);
            $grouped[$officeId][] = $row;
        }

        $finalized = [];
        foreach ($grouped as $officeId => $officeRows) {
            usort($officeRows, fn (array $a, array $b): int => ($a['sort_date'] ?? '') <=> ($b['sort_date'] ?? ''));

            $balance = $this->openingQuantityForLedger($itemId, $officeId, $unitCost);

            foreach ($officeRows as $index => $txn) {
                if ($txn['receipt_qty']) {
                    $balance += (int) $txn['receipt_qty'];
                }
                if ($txn['issue_qty']) {
                    $balance -= (int) $txn['issue_qty'];
                }
                $officeRows[$index]['balance'] = max(0, $balance);
            }

            foreach ($officeRows as $row) {
                $finalized[] = $row;
            }
        }

        usort($finalized, fn (array $a, array $b): int => ($a['sort_date'] ?? '') <=> ($b['sort_date'] ?? ''));

        if ($newestFirst) {
            return array_reverse($finalized);
        }

        return $finalized;
    }

    protected function openingQuantityForLedger(int $itemId, int $officeId, ?float $unitCost): int
    {
        if ($officeId <= 0) {
            return 0;
        }

        if ($unitCost !== null) {
            return StockOpeningBalance::quantityForPosition($itemId, $officeId, $unitCost);
        }

        return (int) StockOpeningBalance::query()
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->sum('quantity');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildRegistryRows(Item $item, ?int $officeId = null): array
    {
        $rowsByItem = $this->buildRegistryRowsForItems([$item->id], $officeId, collect([$item->id => $item]));

        return $rowsByItem[$item->id] ?? [];
    }

    /**
     * @param  array<int, int>  $itemIds
     * @param  int|array<int, int>|null  $officeIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function buildRegistryRowsForItems(
        array $itemIds,
        int|array|null $officeIds = null,
        ?Collection $itemsById = null,
    ): array {
        $itemIds = array_values(array_unique(array_filter($itemIds, fn (int $id): bool => $id > 0)));

        if ($itemIds === []) {
            return [];
        }

        $normalizedOfficeIds = match (true) {
            $officeIds === null => [],
            is_int($officeIds) => $officeIds > 0 ? [$officeIds] : [],
            default => array_values(array_unique(array_filter($officeIds, fn (int $id): bool => $id > 0))),
        };

        $itemsById ??= Item::query()->whereIn('id', $itemIds)->get()->keyBy('id');

        /** @var array<int, array<int, array<string, mixed>>> $rowsByItem */
        $rowsByItem = array_fill_keys($itemIds, []);

        Issuance::query()
            ->with(['office', 'issuedTo', 'item'])
            ->whereIn('item_id', $itemIds)
            ->when($normalizedOfficeIds !== [], fn ($q) => $q->whereIn('office_id', $normalizedOfficeIds))
            ->orderBy('issuance_date')
            ->get()
            ->each(function (Issuance $issuance) use (&$rowsByItem, $itemsById): void {
                $item = $issuance->item ?? $itemsById->get($issuance->item_id);

                $rowsByItem[$issuance->item_id][] = [
                    'office_id' => $issuance->office_id,
                    'date' => $issuance->issuance_date?->format('Y-m-d'),
                    'reference' => $issuance->controlNumber(),
                    'property_number' => $issuance->property_number ?? $item?->item_code,
                    'description' => $this->itemDescription($item),
                    'estimated_useful_life' => $issuance->estimated_useful_life ?? $item?->estimated_useful_life,
                    'issued_qty' => $issuance->quantity,
                    'issued_office' => IssuanceDistributionVisibility::holderLabelForIssuance($issuance)
                        ?? $issuance->issuedTo?->name
                        ?? $issuance->office?->name,
                    'returned_qty' => null,
                    'returned_office' => null,
                    'reissued_qty' => null,
                    'reissued_office' => null,
                    'disposed_qty' => null,
                    'balance_qty' => null,
                    'remarks' => $issuance->remarks,
                ];
            });

        Disposal::query()
            ->with('item')
            ->whereIn('item_id', $itemIds)
            ->when($normalizedOfficeIds !== [], fn ($q) => $q->whereIn('office_id', $normalizedOfficeIds))
            ->orderBy('disposal_date')
            ->get()
            ->each(function (Disposal $disposal) use (&$rowsByItem, $itemsById): void {
                $item = $disposal->item ?? $itemsById->get($disposal->item_id);

                $rowsByItem[$disposal->item_id][] = [
                    'office_id' => $disposal->office_id,
                    'date' => $disposal->disposal_date?->format('Y-m-d'),
                    'reference' => $disposal->reference_code,
                    'property_number' => $disposal->property_number ?? $item?->item_code,
                    'description' => $this->itemDescription($item),
                    'estimated_useful_life' => $item?->estimated_useful_life,
                    'issued_qty' => null,
                    'issued_office' => null,
                    'returned_qty' => null,
                    'returned_office' => null,
                    'reissued_qty' => null,
                    'reissued_office' => null,
                    'disposed_qty' => $disposal->quantity,
                    'balance_qty' => null,
                    'remarks' => $disposal->reason,
                ];
            });

        Transfer::query()
            ->with(['toOffice', 'fromOffice'])
            ->where('transfer_type', 'return')
            ->whereIn('item_id', $itemIds)
            ->when($normalizedOfficeIds !== [], fn ($q) => $q->whereIn('to_office_id', $normalizedOfficeIds))
            ->orderBy('transfer_date')
            ->get()
            ->each(function (Transfer $transfer) use (&$rowsByItem, $itemsById): void {
                $propertyNumber = $transfer->property_number;
                if (blank($propertyNumber)) {
                    return;
                }

                $rows = &$rowsByItem[$transfer->item_id];
                foreach ($rows as $index => $row) {
                    if (($row['property_number'] ?? null) === $propertyNumber && blank($row['returned_qty'] ?? null)) {
                        $rows[$index]['returned_qty'] = $transfer->quantity;
                        $rows[$index]['returned_office'] = $transfer->toOffice?->name
                            ?? $transfer->from_accountable_officer
                            ?? '—';
                        $rows[$index]['date'] = $row['date'] ?? $transfer->transfer_date?->format('Y-m-d');

                        return;
                    }
                }

                $item = $itemsById->get($transfer->item_id) ?? Item::query()->find($transfer->item_id);

                $rowsByItem[$transfer->item_id][] = [
                    'office_id' => $transfer->to_office_id,
                    'date' => $transfer->transfer_date?->format('Y-m-d'),
                    'reference' => $transfer->reference_code,
                    'property_number' => $propertyNumber,
                    'description' => $this->itemDescription($item),
                    'estimated_useful_life' => $item?->estimated_useful_life,
                    'issued_qty' => null,
                    'issued_office' => null,
                    'returned_qty' => $transfer->quantity,
                    'returned_office' => $transfer->toOffice?->name ?? '—',
                    'reissued_qty' => null,
                    'reissued_office' => null,
                    'disposed_qty' => null,
                    'balance_qty' => null,
                    'remarks' => $transfer->remarks,
                ];
            });

        return $rowsByItem;
    }

    public function downloadItemReport(Item $item, string $formSlug, ?int $officeId = null, ?float $unitCost = null): StreamedResponse
    {
        $item->loadMissing('category');
        $office = $officeId ? Office::query()->find($officeId) : null;
        $templatePath = $this->resolveItemReportTemplate($item, $formSlug);
        $filename = OwwaExportFilename::itemReport($formSlug, (string) ($item->item_code ?? $item->id));

        if ($formSlug === 'annex_a1') {
            $propertyClass = ItemPropertyClass::resolveForExport($item->property_class);
            $sheetName = ItemPropertyClass::sheetNameForForm('annex_a1', $propertyClass) ?? 'OFFICE EQUIPMENT';

            return $this->templateExport->downloadAnnexA1Spreadsheet(
                [
                    [
                        'sheetName' => $sheetName,
                        'blocks' => [$this->buildAnnexA1Block($item, $office, $officeId, null, null, $unitCost)],
                    ],
                ],
                $filename,
                $templatePath,
            );
        }

        if ($formSlug === 'annex_a4') {
            $propertyClass = ItemPropertyClass::resolveForExport($item->property_class);
            $sheetName = ItemPropertyClass::sheetNameForForm('annex_a4', $propertyClass) ?? 'OFFICE EQUIPMENT';

            return $this->templateExport->downloadAnnexA4Spreadsheet(
                [
                    [
                        'sheetName' => $sheetName,
                        'header' => [
                            'entity_name' => $office?->name ?? '',
                            'fund_cluster' => '',
                            'property_type' => ItemPropertyClass::propertyTypeLabel($propertyClass),
                        ],
                        'entries' => $this->buildRegistryRows($item, $officeId),
                    ],
                ],
                $filename,
                $templatePath,
            );
        }

        $cellValues = match ($formSlug) {
            'sc' => $this->cellValuesForSc($item, $office, $officeId, $unitCost),
            'pc' => $this->cellValuesForPropertyCard($item, $office, $officeId, $unitCost),
            default => [],
        };

        $sheet = $this->resolveItemReportSheet($item, $formSlug);

        return $this->templateExport->downloadFromTemplate(
            $templatePath,
            $cellValues,
            $filename,
            $sheet['sheetIndex'],
            $sheet['sheetName'],
        );
    }

    public function propertyCardFilledSpreadsheet(Item $item, Office $office, ?int $officeId, ?float $unitCost = null): Spreadsheet
    {
        return $this->templateExport->renderFilledSpreadsheet(
            PropertyCardLayout::templatePath(),
            $this->cellValuesForPropertyCard($item, $office, $officeId, $unitCost),
        );
    }

    public function stockCardFilledSpreadsheet(Item $item, Office $office, ?int $officeId, ?float $unitCost = null): Spreadsheet
    {
        $templatePath = $this->resolveItemReportTemplate($item, 'sc');
        $sheet = $this->resolveItemReportSheet($item, 'sc');

        return $this->templateExport->renderFilledSpreadsheet(
            $templatePath,
            $this->cellValuesForSc($item, $office, $officeId, $unitCost),
            $sheet['sheetIndex'],
            $sheet['sheetName'],
        );
    }

    /**
     * @return Collection<int, array{item_id: int, office_id: int}>
     */
    public function stockLevelPairsForPropertyCardBulk(?int $categoryId, ?string $search, string $restockFilter = 'active'): Collection
    {
        $scopedOfficeId = auth()->user()?->office_id ? (int) auth()->user()->office_id : null;

        $pairs = app(StockLevelExportService::class)->resolvePairs(
            categoryId: $categoryId,
            search: $search,
            restockFilter: $restockFilter,
            scopedOfficeId: $scopedOfficeId,
        );

        return $pairs
            ->filter(function (array $pair): bool {
                $item = Item::query()->with('category')->find($pair['item_id']);

                return $item?->category?->getTemplateSlug() === 'ppe';
            })
            ->map(fn (array $pair): array => [
                'item_id' => $pair['item_id'],
                'office_id' => $pair['office_id'],
                'unit_cost' => $pair['unit_cost'],
            ])
            ->values();
    }

    public function downloadStockCardBulk(Collection $pairs): StreamedResponse
    {
        $merged = $this->buildStockCardBulkSpreadsheet($pairs);
        $writer = new Xlsx($merged);
        $downloadName = OwwaExportFilename::batch('SC');

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $downloadName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function downloadStockCardBulkPdf(Collection $pairs): Response
    {
        return $this->templateExport->pdfDownloadResponse(
            $this->buildStockCardBulkSpreadsheet($pairs),
            OwwaExportFilename::batch('SC', ext: 'pdf'),
        );
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>  $pairs
     */
    protected function buildStockCardBulkSpreadsheet(Collection $pairs): Spreadsheet
    {
        $merged = new Spreadsheet;
        $removedDefaultSheet = false;
        $usedSheetTitles = [];

        foreach ($pairs as $pair) {
            $itemId = (int) ($pair['item_id'] ?? 0);
            $officeId = (int) ($pair['office_id'] ?? 0);
            $unitCost = isset($pair['unit_cost']) ? (float) $pair['unit_cost'] : null;

            if ($itemId <= 0 || $officeId <= 0) {
                continue;
            }

            $item = Item::query()->with('category')->find($itemId);
            if ($item === null || $item->category?->getTemplateSlug() !== 'consumables') {
                continue;
            }

            $office = Office::query()->find($officeId);
            if ($office === null) {
                continue;
            }

            OwwaExportDiagnostics::info('stock_card_sheet_start', [
                'item_id' => $itemId,
                'office_id' => $officeId,
                'unit_cost' => $unitCost,
                'item_code' => $item->item_code,
            ]);

            $source = $this->stockCardFilledSpreadsheet($item, $office, $officeId, $unitCost);
            $sheet = $source->getSheet(0);

            $titleBase = filled($item->item_code) ? (string) $item->item_code : 'item_'.$item->id;
            $sheet->setTitle($this->uniqueExcelSheetTitle($titleBase, $usedSheetTitles));

            $merged->addExternalSheet($sheet);

            if (! $removedDefaultSheet) {
                $merged->removeSheetByIndex(0);
                $removedDefaultSheet = true;
            }

            $source->disconnectWorksheets();
            unset($source, $sheet);
            gc_collect_cycles();

            OwwaExportDiagnostics::info('stock_card_sheet_done', [
                'item_id' => $itemId,
                'office_id' => $officeId,
            ]);
        }

        if (! $removedDefaultSheet) {
            abort(404, 'No matching stock cards could be built for the selected positions.');
        }

        $merged->setActiveSheetIndex(0);

        return $merged;
    }

    public function downloadPropertyCardBulk(Collection $pairs): StreamedResponse
    {
        $merged = $this->buildPropertyCardBulkSpreadsheet($pairs);
        $writer = new Xlsx($merged);
        $downloadName = OwwaExportFilename::batch('PC');

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $downloadName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function downloadPropertyCardBulkPdf(Collection $pairs): Response
    {
        return $this->templateExport->pdfDownloadResponse(
            $this->buildPropertyCardBulkSpreadsheet($pairs),
            OwwaExportFilename::batch('PC', ext: 'pdf'),
        );
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost?: float|null}>  $pairs
     */
    protected function buildPropertyCardBulkSpreadsheet(Collection $pairs): Spreadsheet
    {
        $merged = new Spreadsheet;
        $removedDefaultSheet = false;
        $usedSheetTitles = [];

        foreach ($pairs as $pair) {
            $itemId = (int) ($pair['item_id'] ?? 0);
            $officeId = (int) ($pair['office_id'] ?? 0);
            $unitCost = isset($pair['unit_cost']) ? (float) $pair['unit_cost'] : null;

            if ($itemId <= 0 || $officeId <= 0) {
                continue;
            }

            $item = Item::query()->with('category')->find($itemId);
            if ($item === null || $item->category?->getTemplateSlug() !== 'ppe') {
                continue;
            }

            $office = Office::query()->find($officeId);
            if ($office === null) {
                continue;
            }

            $source = $this->propertyCardFilledSpreadsheet($item, $office, $officeId, $unitCost);
            $sheet = $source->getSheet(0);

            $titleBase = filled($item->item_code) ? (string) $item->item_code : 'item_'.$item->id;
            $sheet->setTitle($this->uniqueExcelSheetTitle($titleBase, $usedSheetTitles));

            $merged->addExternalSheet($sheet);

            if (! $removedDefaultSheet) {
                $merged->removeSheetByIndex(0);
                $removedDefaultSheet = true;
            }

            $source->disconnectWorksheets();
            unset($source, $sheet);
            gc_collect_cycles();
        }

        if (! $removedDefaultSheet) {
            abort(404, 'No matching property cards could be built for the selected positions.');
        }

        $merged->setActiveSheetIndex(0);

        return $merged;
    }

    /**
     * @param  array<string, true>  $usedTitles
     */
    protected function uniqueExcelSheetTitle(string $base, array &$usedTitles): string
    {
        $invalid = ['\\', '/', '*', '?', ':', '[', ']'];
        $cleaned = str_replace($invalid, '', $base);
        $cleaned = trim($cleaned) !== '' ? trim($cleaned) : 'Sheet';

        $candidate = mb_substr($cleaned, 0, 31);
        $i = 2;

        while (isset($usedTitles[$candidate])) {
            $suffix = '_'.$i;
            $maxBase = 31 - mb_strlen($suffix);
            $candidate = mb_substr($cleaned, 0, max(1, $maxBase)).$suffix;
            $i++;
        }

        $usedTitles[$candidate] = true;

        return $candidate;
    }

    public function downloadAnnexA1Bulk(Collection $pairs): StreamedResponse
    {
        $tabs = $this->buildAnnexA1BulkTabs($pairs);
        abort_if($tabs === [], 404);

        $filename = OwwaExportFilename::batch('AnnexA1');

        return $this->templateExport->downloadAnnexA1Spreadsheet($tabs, $filename);
    }

    public function downloadAnnexA1BulkPdf(Collection $pairs): Response
    {
        $tabs = $this->buildAnnexA1BulkTabs($pairs);
        abort_if($tabs === [], 404);

        return $this->templateExport->downloadAnnexA1SpreadsheetPdf(
            $tabs,
            OwwaExportFilename::batch('AnnexA1'),
        );
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost?: float|null}>  $pairs
     * @return array<int, array{sheetName: string, blocks?: array<int, array{header: array<string, string|null>, transactions: array<int, array<string, mixed>>}>}>
     */
    protected function buildAnnexA1BulkTabs(Collection $pairs): array
    {
        $resolvedPairs = $this->resolveSemiExpendableBulkPairs($pairs);

        $allItemIds = $resolvedPairs->pluck('item_id')->unique()->values()->all();
        $allOfficeIds = $resolvedPairs->pluck('office_id')->unique()->values()->all();
        $itemsById = $resolvedPairs->pluck('item', 'item_id');

        $allHistories = $this->buildTransactionHistoriesForItems(
            $allItemIds,
            $allOfficeIds,
            true,
            $itemsById,
        );

        $latestPropertyNumbers = Issuance::query()
            ->whereIn('item_id', $allItemIds)
            ->whereNotNull('property_number')
            ->orderByDesc('issuance_date')
            ->get()
            ->unique('item_id')
            ->pluck('property_number', 'item_id');

        /** @var array<string, array<int, array{item: Item, office: ?Office, office_id: ?int}>> $grouped */
        $grouped = [];

        foreach ($resolvedPairs as $pair) {
            $propertyClass = ItemPropertyClass::resolveForExport($pair['item']->property_class);
            $grouped[$propertyClass][] = $pair;
        }

        $tabs = [];
        foreach ($grouped as $propertyClass => $entries) {
            $sheetName = ItemPropertyClass::sheetNameForForm('annex_a1', $propertyClass) ?? 'OFFICE EQUIPMENT';
            $tabs[] = [
                'sheetName' => $sheetName,
                'blocks' => array_map(
                    function (array $entry) use ($allHistories, $latestPropertyNumbers): array {
                        $itemHistory = $allHistories[$entry['item_id']] ?? [];
                        $officeHistory = array_values(array_filter(
                            $itemHistory,
                            fn (array $txn): bool => (int) ($txn['office_id'] ?? 0) === (int) $entry['office_id'],
                        ));

                        return $this->buildAnnexA1Block(
                            $entry['item'],
                            $entry['office'],
                            $entry['office_id'],
                            $officeHistory,
                            $latestPropertyNumbers->get($entry['item_id']),
                            $entry['unit_cost'] ?? null,
                        );
                    },
                    $entries,
                ),
            ];
        }

        usort($tabs, fn (array $a, array $b): int => strcmp($a['sheetName'], $b['sheetName']));

        return $tabs;
    }

    public function downloadAnnexA4Bulk(Collection $pairs): StreamedResponse
    {
        $tabs = $this->buildAnnexA4ExportTabs($pairs);
        abort_if($tabs === [], 404);

        $filename = OwwaExportFilename::batch('AnnexA4');

        return $this->templateExport->downloadAnnexA4Spreadsheet($tabs, $filename);
    }

    public function downloadAnnexA4BulkPdf(Collection $pairs): Response
    {
        $tabs = $this->buildAnnexA4ExportTabs($pairs);
        abort_if($tabs === [], 404);

        return $this->templateExport->downloadAnnexA4SpreadsheetPdf(
            $tabs,
            OwwaExportFilename::batch('AnnexA4'),
        );
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost?: float|null}>  $pairs
     * @return array<int, array{sheetName: string, header: array<string, string>, entries: array<int, array<string, mixed>>}>
     */
    public function buildAnnexA4ExportTabs(Collection $pairs): array
    {
        $resolvedPairs = $this->resolveSemiExpendableBulkPairs($pairs);

        /** @var array<string, array<int, array{item: Item, office: ?Office, office_id: ?int, item_id: int}>> $grouped */
        $grouped = [];

        foreach ($resolvedPairs as $pair) {
            $propertyClass = ItemPropertyClass::resolveForExport($pair['item']->property_class);
            $grouped[$propertyClass][] = $pair;
        }

        $tabs = [];
        foreach ($grouped as $propertyClass => $entries) {
            $itemIds = collect($entries)->pluck('item_id')->unique()->values()->all();
            $officeIds = collect($entries)->pluck('office_id')->unique()->values()->all();
            $itemsById = collect($entries)->mapWithKeys(
                fn (array $entry): array => [$entry['item_id'] => $entry['item']],
            );

            $rowsByItem = $this->buildRegistryRowsForItems($itemIds, $officeIds, $itemsById);

            $registryRows = [];
            foreach ($entries as $entry) {
                foreach ($rowsByItem[$entry['item_id']] ?? [] as $row) {
                    if ((int) ($row['office_id'] ?? 0) !== (int) $entry['office_id']) {
                        continue;
                    }

                    unset($row['office_id']);
                    $registryRows[] = $row;
                }
            }

            if ($registryRows === []) {
                continue;
            }

            $office = $entries[0]['office'];
            $sheetName = ItemPropertyClass::sheetNameForForm('annex_a4', $propertyClass) ?? 'OFFICE EQUIPMENT';

            $tabs[] = [
                'sheetName' => $sheetName,
                'header' => [
                    'entity_name' => $office?->name ?? '',
                    'fund_cluster' => '',
                    'property_type' => ItemPropertyClass::propertyTypeLabel($propertyClass),
                ],
                'entries' => $registryRows,
            ];
        }

        usort($tabs, fn (array $a, array $b): int => strcmp($a['sheetName'], $b['sheetName']));

        return $tabs;
    }

    /**
     * @return Collection<int, array{item_id: int, office_id: int, unit_cost: float|null, item: Item, office: ?Office}>
     */
    protected function resolveSemiExpendableBulkPairs(Collection $pairs): Collection
    {
        $normalized = [];

        foreach ($pairs as $pair) {
            $itemId = (int) ($pair['item_id'] ?? 0);
            $officeId = (int) ($pair['office_id'] ?? 0);

            if ($itemId <= 0 || $officeId <= 0) {
                continue;
            }

            $normalized[] = [
                'item_id' => $itemId,
                'office_id' => $officeId,
                'unit_cost' => isset($pair['unit_cost']) ? (float) $pair['unit_cost'] : null,
            ];
        }

        if ($normalized === []) {
            return collect();
        }

        $itemIds = collect($normalized)->pluck('item_id')->unique()->values()->all();
        $officeIds = collect($normalized)->pluck('office_id')->unique()->values()->all();

        $items = Item::query()->with('category')->whereIn('id', $itemIds)->get()->keyBy('id');
        $offices = Office::query()->whereIn('id', $officeIds)->get()->keyBy('id');

        $resolved = collect();

        foreach ($normalized as $pair) {
            $item = $items->get($pair['item_id']);

            if ($item === null || $item->category?->getTemplateSlug() !== 'semi_expendable') {
                continue;
            }

            $resolved->push([
                'item_id' => $pair['item_id'],
                'office_id' => $pair['office_id'],
                'unit_cost' => $pair['unit_cost'],
                'item' => $item,
                'office' => $offices->get($pair['office_id']),
            ]);
        }

        return $resolved;
    }

    /**
     * @return Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>
     */
    public function stockLevelPairsForAnnexA1Bulk(?int $categoryId, ?string $search, string $restockFilter = 'active'): Collection
    {
        $scopedOfficeId = auth()->user()?->office_id ? (int) auth()->user()->office_id : null;

        return app(StockLevelExportService::class)->resolvePairs(
            categoryId: $categoryId,
            search: $search,
            restockFilter: $restockFilter,
            scopedOfficeId: $scopedOfficeId,
        )->map(fn (array $pair): array => [
            'item_id' => $pair['item_id'],
            'office_id' => $pair['office_id'],
            'unit_cost' => $pair['unit_cost'],
        ])->values();
    }

    public function countStockLevelItemsMissingPropertyClass(?int $categoryId, ?string $search, string $restockFilter = 'active'): int
    {
        $scopedOfficeId = auth()->user()?->office_id ? (int) auth()->user()->office_id : null;

        $itemIds = app(StockLevelExportService::class)
            ->resolvePairs($categoryId, $search, $restockFilter, $scopedOfficeId)
            ->pluck('item_id')
            ->unique()
            ->values();

        if ($itemIds->isEmpty()) {
            return 0;
        }

        return Item::query()
            ->whereIn('id', $itemIds)
            ->whereNull('property_class')
            ->count();
    }

    /**
     * @return array{sheetIndex: int, sheetName: ?string}
     */
    public function resolveItemReportSheet(Item $item, string $formSlug): array
    {
        $slug = $item->category?->getTemplateSlug() ?? 'consumables';
        $entry = config("owwa_templates.item_report.{$slug}.{$formSlug}", []);

        if (is_array($entry) && isset($entry['sheet_name']) && is_string($entry['sheet_name'])) {
            return [
                'sheetIndex' => (int) ($entry['sheet_index'] ?? 0),
                'sheetName' => $entry['sheet_name'],
            ];
        }

        if ($slug === 'semi_expendable' && $formSlug === 'annex_a1') {
            return [
                'sheetIndex' => 0,
                'sheetName' => AnnexA1BlockLayout::templateSheetName(),
            ];
        }

        if ($slug === 'semi_expendable' && $formSlug === 'annex_a4') {
            return [
                'sheetIndex' => 0,
                'sheetName' => AnnexA4Layout::templateSheetName(),
            ];
        }

        return [
            'sheetIndex' => is_array($entry) ? (int) ($entry['sheet_index'] ?? 0) : 0,
            'sheetName' => is_array($entry) && isset($entry['sheet_name']) ? (string) $entry['sheet_name'] : null,
        ];
    }

    public function downloadPhysicalCount(PhysicalCountSession $session): StreamedResponse
    {
        $session->loadMissing(['office', 'lines.item']);

        if ($session->count_type === PhysicalCountSession::TYPE_RPCSP) {
            return $this->downloadRpcspPhysicalCount($session);
        }

        $formCode = $this->physicalCountFormCode($session);
        $templatePath = (string) (OwwaCellMapping::form($formCode)['template'] ?? '');

        if ($templatePath === '') {
            $templatePath = match ($session->count_type) {
                PhysicalCountSession::TYPE_RPCPPE => 'ppe/Physical Count/Appendix 73 - RPCPPE.xlsx',
                default => 'Consumable/Stock Levels & Recording/Appendix 66 - RPCI.xlsx',
            };
        }

        $filename = OwwaExportFilename::physicalCount(
            (string) $session->count_type,
            (string) $session->reference_code,
        );
        $sheet = $this->resolvePhysicalCountSheet($session);

        return $this->templateExport->downloadPhysicalCountSpreadsheet(
            $session,
            $formCode,
            $templatePath,
            $filename,
            $sheet['sheetIndex'],
            $sheet['sheetName'],
        );
    }

    public function downloadPhysicalCountPdf(PhysicalCountSession $session): Response
    {
        $session->loadMissing(['office', 'lines.item']);

        if ($session->count_type === PhysicalCountSession::TYPE_RPCSP) {
            return $this->downloadRpcspPhysicalCountPdf($session);
        }

        $formCode = $this->physicalCountFormCode($session);
        $templatePath = (string) (OwwaCellMapping::form($formCode)['template'] ?? '');

        if ($templatePath === '') {
            $templatePath = match ($session->count_type) {
                PhysicalCountSession::TYPE_RPCPPE => 'ppe/Physical Count/Appendix 73 - RPCPPE.xlsx',
                default => 'Consumable/Stock Levels & Recording/Appendix 66 - RPCI.xlsx',
            };
        }

        $filename = OwwaExportFilename::physicalCount(
            (string) $session->count_type,
            (string) $session->reference_code,
        );
        $sheet = $this->resolvePhysicalCountSheet($session);

        return $this->templateExport->downloadPhysicalCountSpreadsheetPdf(
            $session,
            $formCode,
            $templatePath,
            $filename,
            $sheet['sheetIndex'],
            $sheet['sheetName'],
        );
    }

    public function downloadRpcspPhysicalCount(PhysicalCountSession $session): StreamedResponse
    {
        $formCode = $this->physicalCountFormCode($session);
        $templatePath = (string) (OwwaCellMapping::form($formCode)['template'] ?? '');
        $templatePath = $templatePath !== ''
            ? $templatePath
            : 'Semi-Expendable/Physical Count/Inventory-Annex-A.8-RPCSP - REPORT.xlsx';

        $tabs = $this->buildRpcspPhysicalCountTabs($session);
        $filename = OwwaExportFilename::physicalCount(
            (string) $session->count_type,
            (string) $session->reference_code,
        );

        return $this->templateExport->downloadRpcspPhysicalCountSpreadsheet($tabs, $filename, $templatePath, $session);
    }

    public function downloadRpcspPhysicalCountPdf(PhysicalCountSession $session): Response
    {
        $formCode = $this->physicalCountFormCode($session);
        $templatePath = (string) (OwwaCellMapping::form($formCode)['template'] ?? '');
        $templatePath = $templatePath !== ''
            ? $templatePath
            : 'Semi-Expendable/Physical Count/Inventory-Annex-A.8-RPCSP - REPORT.xlsx';

        $tabs = $this->buildRpcspPhysicalCountTabs($session);
        $filename = OwwaExportFilename::physicalCount(
            (string) $session->count_type,
            (string) $session->reference_code,
        );

        return $this->templateExport->downloadRpcspPhysicalCountSpreadsheetPdf($tabs, $filename, $templatePath, $session);
    }

    /**
     * @return array<int, array{sheetName: string, cellValues: array<string, string|int|float|null>, signaturePairs: array<string, string|int|float|null>}>
     */
    public function buildRpcspPhysicalCountTabs(PhysicalCountSession $session): array
    {
        $session->loadMissing(['office', 'lines.item']);

        /** @var array<string, Collection<int, PhysicalCountLine>> $grouped */
        $grouped = [];

        foreach ($session->lines as $line) {
            $propertyClass = ItemPropertyClass::resolveForExport($line->item?->property_class);
            $grouped[$propertyClass] ??= collect();
            $grouped[$propertyClass]->push($line);
        }

        if ($grouped === []) {
            $fallbackClass = ItemPropertyClass::resolveForExport(
                PhysicalCountPropertyClassResolver::primaryClass($session) ?? $session->property_class,
            );
            $sheetName = ItemPropertyClass::sheetNameForForm('rpcsp', $fallbackClass) ?? 'OFFICE EQUIPMENT';

            return [[
                'sheetName' => $sheetName,
                'propertyClass' => $fallbackClass,
                'lines' => collect(),
            ]];
        }

        $tabs = [];

        foreach ($grouped as $propertyClass => $lines) {
            $baseSheetName = ItemPropertyClass::sheetNameForForm('rpcsp', (string) $propertyClass) ?? 'OFFICE EQUIPMENT';

            $tabs[] = [
                'sheetName' => $baseSheetName,
                'propertyClass' => (string) $propertyClass,
                'lines' => $lines,
            ];
        }

        usort($tabs, fn (array $a, array $b): int => strcmp($a['sheetName'], $b['sheetName']));

        return $tabs;
    }

    /**
     * @return array{sheetIndex: int, sheetName: ?string}
     */
    public function resolvePhysicalCountSheet(PhysicalCountSession $session): array
    {
        if ($session->count_type !== PhysicalCountSession::TYPE_RPCSP) {
            return ['sheetIndex' => 0, 'sheetName' => null];
        }

        $propertyClass = $this->resolvePhysicalCountPropertyClass($session);
        $sheetName = ItemPropertyClass::sheetNameForForm('rpcsp', $propertyClass);

        return [
            'sheetIndex' => 0,
            'sheetName' => $sheetName,
        ];
    }

    protected function resolvePhysicalCountPropertyClass(PhysicalCountSession $session): ?string
    {
        $fromLines = PhysicalCountPropertyClassResolver::primaryClass($session);
        if ($fromLines !== null) {
            return $fromLines;
        }

        if ($session->count_type === PhysicalCountSession::TYPE_RPCPPE && filled($session->ppe_type)) {
            return $session->ppe_type;
        }

        if ($session->count_type === PhysicalCountSession::TYPE_RPCSP && filled($session->property_class)) {
            return $session->property_class;
        }

        if (filled($session->inventory_type_label)) {
            if ($session->count_type === PhysicalCountSession::TYPE_RPCPPE) {
                return PpePropertyType::resolveFromInventoryTypeLabel($session->inventory_type_label);
            }

            return ItemPropertyClass::resolveFromInventoryTypeLabel($session->inventory_type_label);
        }

        return null;
    }

    protected function resolveItemReportTemplate(Item $item, string $formSlug): string
    {
        $fromConfig = config("owwa_templates.item_report.{$item->category?->getTemplateSlug()}.{$formSlug}.file");
        if (is_string($fromConfig) && $fromConfig !== '') {
            return $fromConfig;
        }

        return match ($formSlug) {
            'sc' => 'Consumable/Stock Levels & Recording/Appendix 58 - SC.xlsx',
            'pc' => 'ppe/Accquisition/Appendix 69 - PC.xls',
            'annex_a1' => 'Semi-Expendable/Recording (Stock Levels)/Property-Form-Annex-A.1-Semi-expendable-Property-Card.xlsx',
            'annex_a4' => 'Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xlsx',
            default => 'Consumable/Stock Levels & Recording/Appendix 58 - SC.xlsx',
        };
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForSc(Item $item, ?Office $office, ?int $officeId, ?float $unitCost = null): array
    {
        $values = [
            'A6' => 'Entity Name: '.($office?->name ?? ''),
            'F6' => '',
            'A8' => 'Item : '.$item->name,
            'F8' => 'Stock No. : '.($item->item_code ?? ''),
            'A9' => 'Description : '.($item->description ?? ''),
            'F9' => 'Re-order Point : '.($item->reorder_level ?? 0),
            'A10' => 'Unit of Measurement : '.$item->unit,
        ];

        $startRow = 13;
        $row = $startRow;
        foreach ($this->buildTransactionHistory($item, $officeId, newestFirst: true, unitCost: $unitCost) as $txn) {
            if ($row > $startRow + 49) {
                break;
            }
            $values['A'.$row] = $txn['date'];
            $values['B'.$row] = $txn['reference'];
            $values['C'.$row] = $txn['receipt_qty'] ?? '';
            $values['D'.$row] = $txn['issue_qty'] ?? '';
            $values['E'.$row] = $txn['issue_office'] ?? '';
            $values['F'.$row] = $txn['balance'] ?? 0;
            $values['G'.$row] = $item->days_to_consume ?? '';
            $row++;
        }

        return $values;
    }

    /**
     * @return array{header: array<string, string|null>, transactions: array<int, array<string, mixed>>}
     */
    public function buildAnnexA1Block(
        Item $item,
        ?Office $office,
        ?int $officeId,
        ?array $transactions = null,
        ?string $latestPropertyNumber = null,
        ?float $unitCost = null,
    ): array {
        $propertyClass = ItemPropertyClass::resolveForExport($item->property_class);

        if ($latestPropertyNumber === null) {
            $latestPropertyNumber = $item->resolvedSemiExpendablePropertyNumber($unitCost);
        }

        if ($latestPropertyNumber === null) {
            $latestPropertyNumber = Issuance::query()
                ->where('item_id', $item->id)
                ->whereNotNull('property_number')
                ->orderByDesc('issuance_date')
                ->value('property_number');
        }

        return [
            'header' => [
                'entity_name' => $office?->name ?? '',
                'fund_cluster' => '',
                'property_type' => ItemPropertyClass::propertyTypeLabel($propertyClass),
                'property_number' => $latestPropertyNumber ?? $item->item_code ?? '',
                'description' => $this->itemDescription($item),
            ],
            'transactions' => $transactions ?? $this->buildTransactionHistory($item, $officeId, newestFirst: true, unitCost: $unitCost),
        ];
    }

    /**
     * @param  array<int, array{item: Item, office: ?Office, office_id: ?int}>  $entries
     * @return array<int, array{header: array<string, string|null>, transactions: array<int, array<string, mixed>>}>
     */
    public function buildAnnexA1Blocks(array $entries): array
    {
        return array_map(
            fn (array $entry): array => $this->buildAnnexA1Block(
                $entry['item'],
                $entry['office'],
                $entry['office_id'],
            ),
            array_values($entries),
        );
    }

    /**
     * @return array<string, string|int|float|null>
     *
     * @deprecated Use buildAnnexA1Block() with blocks export API
     */
    protected function cellValuesForAnnexA1(Item $item, ?Office $office, ?int $officeId): array
    {
        $block = $this->buildAnnexA1Block($item, $office, $officeId);
        $blockStart = AnnexA1BlockLayout::FIRST_BLOCK_START_ROW;
        $ledgerStart = AnnexA1BlockLayout::ledgerStartRowForBlockStart($blockStart);
        $values = [];
        AnnexA1BlockLayout::applyHeader($values, $block['header'], $blockStart);

        return array_merge(
            $values,
            app(OwwaTemplateExportService::class)->annexA1LedgerCellValues($block['transactions'], $ledgerStart),
        );
    }

    /**
     * @param  array<int, array{item: Item, office: ?Office, office_id: ?int}>  $entries
     * @return array<string, string|int|float|null>
     *
     * @deprecated Use buildAnnexA1Blocks() with blocks export API
     */
    public function cellValuesForAnnexA1Blocks(array $entries): array
    {
        $values = [];
        $blockStarts = AnnexA1BlockLayout::blockStartRows(
            array_map(
                fn (array $entry): int => count($this->buildTransactionHistory($entry['item'], $entry['office_id'], newestFirst: true)),
                array_values($entries),
            ),
        );

        foreach (array_values($entries) as $index => $entry) {
            $block = $this->buildAnnexA1Block($entry['item'], $entry['office'], $entry['office_id']);
            $blockStart = $blockStarts[$index];
            $ledgerStart = AnnexA1BlockLayout::ledgerStartRowForBlockStart($blockStart);
            AnnexA1BlockLayout::applyHeader($values, $block['header'], $blockStart);
            $values = array_merge(
                $values,
                app(OwwaTemplateExportService::class)->annexA1LedgerCellValues($block['transactions'], $ledgerStart),
            );
        }

        return $values;
    }

    /**
     * @deprecated Use buildAnnexA1Block()
     */
    protected function cellValuesForAnnexA1Block(
        Item $item,
        ?Office $office,
        ?int $officeId,
        int $blockIndex,
    ): array {
        $blockStarts = AnnexA1BlockLayout::blockStartRows([count($this->buildTransactionHistory($item, $officeId, newestFirst: true))]);
        $blockStart = $blockStarts[$blockIndex] ?? AnnexA1BlockLayout::FIRST_BLOCK_START_ROW;
        $block = $this->buildAnnexA1Block($item, $office, $officeId);
        $ledgerStart = AnnexA1BlockLayout::ledgerStartRowForBlockStart($blockStart);
        $values = [];
        AnnexA1BlockLayout::applyHeader($values, $block['header'], $blockStart);

        return array_merge(
            $values,
            app(OwwaTemplateExportService::class)->annexA1LedgerCellValues($block['transactions'], $ledgerStart),
        );
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForPropertyCard(Item $item, ?Office $office, ?int $officeId, ?float $unitCost = null): array
    {
        $transactions = array_map(
            fn (array $txn): array => PropertyCardLayout::normalizeTransactionRow($txn),
            $this->buildTransactionHistory($item, $officeId, newestFirst: true, unitCost: $unitCost),
        );

        return PropertyCardLayout::buildFromItem($item, $office, $officeId, $transactions);
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForAnnexA4(Item $item, ?Office $office, ?int $officeId): array
    {
        $values = [
            'A6' => 'Entity Name: '.($office?->name ?? ''),
            'L6' => '',
            'A7' => 'Semi-Expendable Property: '.ItemPropertyClass::propertyTypeLabel(
                ItemPropertyClass::resolveForExport($item->property_class),
            ),
        ];

        $startRow = 12;
        $row = $startRow;
        foreach ($this->buildRegistryRows($item, $officeId) as $entry) {
            if ($row > $startRow + 30) {
                break;
            }
            $values['A'.$row] = $entry['date'];
            $values['B'.$row] = $entry['reference'];
            $values['C'.$row] = $entry['property_number'];
            $values['D'.$row] = $entry['description'];
            $values['E'.$row] = $entry['estimated_useful_life'] ?? '';
            $values['F'.$row] = $entry['issued_qty'] ?? '';
            $values['G'.$row] = $entry['issued_office'] ?? '';
            $values['L'.$row] = $entry['disposed_qty'] ?? '';
            $values['O'.$row] = $entry['remarks'] ?? '';
            $row++;
        }

        return $values;
    }

    /**
     * @param  Collection<int, PhysicalCountLine>  $lines
     * @return array<string, string|int|float|null>
     */
    public function physicalCountCellValues(
        PhysicalCountSession $session,
        Collection $lines,
        ?string $propertyClass = null,
        ?string $sheetName = null,
        int $blockStartRow = 1,
    ): array {
        return $this->cellValuesForPhysicalCount($session, $propertyClass, $lines, $sheetName, $blockStartRow);
    }

    /**
     * @param  Collection<int, PhysicalCountLine>|null  $lines
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForPhysicalCount(
        PhysicalCountSession $session,
        ?string $propertyClass = null,
        ?Collection $lines = null,
        ?string $sheetName = null,
        int $blockStartRow = 1,
    ): array {
        $formCode = $this->physicalCountFormCode($session);
        $map = OwwaCellMapping::form($formCode);
        $values = [];
        $lineCollection = $lines ?? $session->lines;
        $headerData = $this->physicalCountHeaderData($session, $propertyClass);

        foreach ((array) ($map['header'] ?? []) as $field => $spec) {
            if (! array_key_exists($field, $headerData)) {
                continue;
            }

            $cell = PhysicalCountPageLayout::headerCell($formCode, (string) $field, $blockStartRow);
            if ($cell === '') {
                continue;
            }

            $label = (string) ($spec['label'] ?? '');
            $raw = $headerData[$field];
            $values[$cell] = $label.($raw ?? '');
        }

        $startRow = PhysicalCountPageLayout::detailStartRowForBlock($formCode, $blockStartRow);
        $columns = OwwaCellMapping::detailColumns($formCode);
        $row = $startRow;

        foreach ($lineCollection as $line) {
            $shortageQty = $line->shortageOverageQuantity();
            $unitValue = $this->resolvePhysicalCountLineUnitValue($line, $session);
            $shortageValue = $unitValue !== null ? round($shortageQty * $unitValue, 2) : null;

            foreach ($this->physicalCountLineFieldValues($line, $session, $unitValue, $shortageQty, $shortageValue) as $field => $value) {
                if (! isset($columns[$field])) {
                    continue;
                }

                $values[OwwaCellMapping::columnCell($columns[$field], $row)] = $value;
            }

            $row++;
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    public function physicalCountSignaturePairs(PhysicalCountSession $session): array
    {
        return [
            'certified_by' => $session->certified_by_printed_name ?? '',
            'approved_by' => $session->approved_by_printed_name ?? '',
            'verified_by' => $session->verified_by_printed_name ?? '',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function physicalCountSignatureCells(
        PhysicalCountSession $session,
        ?string $sheetName = null,
        int $rowOffset = 0,
    ): array {
        $formCode = $this->physicalCountFormCode($session);
        $useMaster = $formCode === 'RPCSP'
            && ($sheetName ?? $this->resolvePhysicalCountSheet($session)['sheetName']) === 'RPCSP';
        $pairs = $this->physicalCountSignaturePairs($session);
        $cells = [];

        foreach ($pairs as $field => $value) {
            $cells[OwwaCellMapping::physicalCountSignatureCell(
                $formCode,
                $field,
                $rowOffset,
                $useMaster,
            )] = $value;
        }

        return $cells;
    }

    protected function physicalCountFormCode(PhysicalCountSession $session): string
    {
        return match ($session->count_type) {
            PhysicalCountSession::TYPE_RPCPPE => 'RPCPPE',
            PhysicalCountSession::TYPE_RPCSP => 'RPCSP',
            default => 'RPCI',
        };
    }

    /**
     * @return array<string, string|null>
     */
    protected function physicalCountHeaderData(PhysicalCountSession $session, ?string $propertyClass = null): array
    {
        $inventoryType = match (true) {
            $session->count_type === PhysicalCountSession::TYPE_RPCI && filled($session->inventory_type_label) => (string) $session->inventory_type_label,
            $session->count_type === PhysicalCountSession::TYPE_RPCI && filled($session->inventory_type) => ConsumableInventoryType::label($session->inventory_type),
            $propertyClass !== null && $session->count_type === PhysicalCountSession::TYPE_RPCPPE => PpePropertyType::propertyTypeLabel($propertyClass),
            $propertyClass !== null => ItemPropertyClass::propertyTypeLabel($propertyClass),
            filled($session->inventory_type_label) => (string) $session->inventory_type_label,
            default => PhysicalCountPropertyClassResolver::inventoryTypeLabel($session),
        };

        return [
            'inventory_type' => $inventoryType,
            'count_date' => $session->count_date?->format('Y-m-d') ?? '',
            'fund_cluster' => '',
            'accountable_officer' => $this->formatPhysicalCountAccountableOfficerClause($session),
        ];
    }

    protected function formatPhysicalCountAccountableOfficerClause(PhysicalCountSession $session): string
    {
        $officerParts = array_values(array_filter([
            $session->accountable_officer_name,
            $session->accountable_officer_designation,
            $session->office?->name,
        ], static fn (?string $part): bool => filled($part)));

        $assumptionDate = $session->date_of_assumption?->format('Y-m-d') ?? '';
        $accountabilityPhrase = ' is accountable, having assumed such accountability on ';

        if ($officerParts === []) {
            return $assumptionDate !== ''
                ? ltrim($accountabilityPhrase).$assumptionDate.'.'
                : '';
        }

        $clause = implode(', ', $officerParts).$accountabilityPhrase.$assumptionDate;

        return $assumptionDate !== '' ? $clause.'.' : $clause;
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function physicalCountLineFieldValues(
        PhysicalCountLine $line,
        PhysicalCountSession $session,
        ?float $unitValue,
        int $shortageQty,
        ?float $shortageValue,
    ): array {
        $article = $line->article ?? $line->item?->name;
        $description = $line->description ?? $line->item?->description;
        $unit = $line->unit_of_measure ?? $line->item?->unit;

        $common = [
            'article' => $article,
            'description' => $description,
            'unit_of_measure' => $unit,
            'unit_value' => $unitValue,
            'balance_per_card' => $line->balance_per_card,
            'on_hand_count' => $line->on_hand_count,
            'shortage_qty' => $shortageQty,
            'shortage_value' => $shortageValue,
            'remarks' => $line->remarks,
        ];

        return match ($session->count_type) {
            PhysicalCountSession::TYPE_RPCPPE, PhysicalCountSession::TYPE_RPCSP => array_merge($common, [
                'property_number' => $line->property_number ?? $line->stock_number ?? $line->item?->item_code,
            ]),
            default => array_merge($common, [
                'stock_number' => $line->stock_number ?? $line->item?->item_code,
            ]),
        };
    }

    protected function resolvePhysicalCountLineUnitValue(PhysicalCountLine $line, PhysicalCountSession $session): ?float
    {
        $itemId = $line->item_id;
        if ($itemId === null) {
            return null;
        }

        $officeId = $session->office_id;

        $acquisitionCost = Acquisition::query()
            ->where('item_id', $itemId)
            ->when($officeId !== null, fn ($query) => $query->where('office_id', $officeId))
            ->whereNotNull('unit_cost')
            ->orderByDesc('acquisition_date')
            ->orderByDesc('id')
            ->value('unit_cost');

        if ($acquisitionCost !== null) {
            return (float) $acquisitionCost;
        }

        if (filled($line->property_number)) {
            $unitCost = InventoryUnit::query()
                ->whereHas('acquisition', fn ($query) => $query->where('item_id', $itemId))
                ->where('property_number', $line->property_number)
                ->with('acquisition:id,unit_cost')
                ->first()
                ?->acquisition
                ?->unit_cost;

            if ($unitCost !== null) {
                return (float) $unitCost;
            }
        }

        $issuanceCost = Issuance::query()
            ->where('item_id', $itemId)
            ->when($officeId !== null, fn ($query) => $query->where('office_id', $officeId))
            ->whereNotNull('unit_cost')
            ->orderByDesc('issuance_date')
            ->orderByDesc('id')
            ->value('unit_cost');

        return $issuanceCost !== null ? (float) $issuanceCost : null;
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     * @param  array<string, string|int|float|null>  $pairs
     */
    protected function applyPhysicalCountSignatures(
        array &$values,
        PhysicalCountSession $session,
        string $formCode,
        array $pairs,
        ?string $sheetName = null,
    ): void {
        $useMaster = false;

        if ($formCode === 'RPCSP') {
            $resolvedSheetName = $sheetName ?? $this->resolvePhysicalCountSheet($session)['sheetName'];
            $useMaster = $resolvedSheetName === 'RPCSP';
        }

        foreach ($pairs as $field => $value) {
            $values[OwwaCellMapping::physicalCountSignatureCell($formCode, (string) $field, 0, $useMaster)] = $value;
        }
    }

    protected function itemDescription(?Item $item): string
    {
        if ($item === null) {
            return '';
        }

        $parts = array_filter([$item->name, $item->description]);

        return implode(' - ', $parts);
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableItemReportForms(Item $item): array
    {
        $slug = $item->category?->getTemplateSlug() ?? 'consumables';
        $configForms = config("owwa_templates.item_report.{$slug}", []);

        if (is_array($configForms) && $configForms !== []) {
            $forms = [];
            foreach ($configForms as $key => $entry) {
                $forms[$key] = is_array($entry) && isset($entry['label'])
                    ? $entry['label']
                    : ucfirst(str_replace('_', ' ', $key));
            }

            return $forms;
        }

        return match ($slug) {
            'ppe' => ['pc' => 'Appendix 69 - Property Card'],
            'semi_expendable' => [
                'annex_a1' => 'Annex A.1 - Semi-Expendable Property Card',
                'annex_a4' => 'Annex A.4 - Registry of Semi-Expendable Property Issued',
            ],
            default => [
                'sc' => 'Appendix 58 - Stock Card',
            ],
        };
    }
}
