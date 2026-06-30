<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\Transfer;
use App\Support\AnnexA1BlockLayout;
use App\Support\ItemPropertyClass;
use App\Support\OwwaCellMapping;
use App\Support\OwwaExportFilename;
use App\Support\PropertyCardLayout;
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
    public function buildTransactionHistory(Item $item, ?int $officeId = null, bool $newestFirst = false): array
    {
        $rows = [];

        $acquisitions = Acquisition::query()
            ->where('item_id', $item->id)
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->orderBy('acquisition_date')
            ->get();

        foreach ($acquisitions as $acquisition) {
            $rows[] = [
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
                'item_code' => $item->item_code,
            ];
        }

        $issuances = Issuance::query()
            ->with(['office', 'issuedTo', 'requisition'])
            ->where('item_id', $item->id)
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->orderBy('issuance_date')
            ->get();

        foreach ($issuances as $issuance) {
            $rows[] = [
                'sort_date' => $issuance->issuance_date,
                'date' => $issuance->issuance_date?->format('Y-m-d'),
                'reference' => $issuance->requisition?->reference_code ?? $issuance->reference_code,
                'type' => 'issue',
                'receipt_qty' => null,
                'issue_qty' => $issuance->quantity,
                'issue_office' => $issuance->office?->name,
                'office_officer' => $issuance->issuedTo?->name ?? $issuance->office?->name,
                'remarks' => $issuance->remarks,
                'property_number' => $issuance->property_number,
            ];
        }

        $transfersIn = Transfer::query()
            ->with(['fromOffice', 'toOffice'])
            ->where('item_id', $item->id)
            ->when($officeId, fn ($q) => $q->where('to_office_id', $officeId))
            ->orderBy('transfer_date')
            ->get();

        foreach ($transfersIn as $transfer) {
            $rows[] = [
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
            ];
        }

        $transfersOut = Transfer::query()
            ->with(['fromOffice', 'toOffice'])
            ->where('item_id', $item->id)
            ->when($officeId, fn ($q) => $q->where('from_office_id', $officeId))
            ->orderBy('transfer_date')
            ->get();

        foreach ($transfersOut as $transfer) {
            $rows[] = [
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
            ];
        }

        $disposals = Disposal::query()
            ->with('office')
            ->where('item_id', $item->id)
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->orderBy('disposal_date')
            ->get();

        foreach ($disposals as $disposal) {
            $rows[] = [
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
            ];
        }

        usort($rows, fn (array $a, array $b): int => ($a['sort_date'] ?? '') <=> ($b['sort_date'] ?? ''));

        $balance = 0;
        foreach ($rows as $index => $txn) {
            if ($txn['receipt_qty']) {
                $balance += (int) $txn['receipt_qty'];
            }
            if ($txn['issue_qty']) {
                $balance -= (int) $txn['issue_qty'];
            }
            $rows[$index]['balance'] = max(0, $balance);
        }

        if ($newestFirst) {
            return array_reverse($rows);
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildRegistryRows(Item $item, ?int $officeId = null): array
    {
        $rows = [];

        Issuance::query()
            ->with(['office', 'issuedTo'])
            ->where('item_id', $item->id)
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->orderBy('issuance_date')
            ->each(function (Issuance $issuance) use (&$rows): void {
                $rows[] = [
                    'date' => $issuance->issuance_date?->format('Y-m-d'),
                    'reference' => $issuance->reference_code,
                    'property_number' => $issuance->property_number ?? $issuance->item?->item_code,
                    'description' => $this->itemDescription($issuance->item),
                    'estimated_useful_life' => $issuance->estimated_useful_life ?? $issuance->item?->estimated_useful_life,
                    'issued_qty' => $issuance->quantity,
                    'issued_office' => $issuance->issuedTo?->name ?? $issuance->office?->name,
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
            ->where('item_id', $item->id)
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->orderBy('disposal_date')
            ->each(function (Disposal $disposal) use (&$rows): void {
                $rows[] = [
                    'date' => $disposal->disposal_date?->format('Y-m-d'),
                    'reference' => $disposal->reference_code,
                    'property_number' => $disposal->property_number ?? $disposal->item?->item_code,
                    'description' => $this->itemDescription($disposal->item),
                    'estimated_useful_life' => $disposal->item?->estimated_useful_life,
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

        return $rows;
    }

    public function downloadItemReport(Item $item, string $formSlug, ?int $officeId = null): StreamedResponse
    {
        $item->loadMissing('category');
        $office = $officeId ? Office::query()->find($officeId) : null;
        $templatePath = $this->resolveItemReportTemplate($item, $formSlug);
        $cellValues = match ($formSlug) {
            'sc' => $this->cellValuesForSc($item, $office, $officeId),
            'pc' => $this->cellValuesForPropertyCard($item, $office, $officeId),
            'annex_a1' => $this->cellValuesForAnnexA1($item, $office, $officeId),
            'annex_a4' => $this->cellValuesForAnnexA4($item, $office, $officeId),
            default => [],
        };

        $filename = OwwaExportFilename::itemReport($formSlug, (string) ($item->item_code ?? $item->id));

        if ($formSlug === 'annex_a1') {
            $propertyClass = ItemPropertyClass::resolveForExport($item->property_class);
            $sheetName = ItemPropertyClass::sheetNameForForm('annex_a1', $propertyClass) ?? 'OFFICE EQUIPMENT';

            return $this->templateExport->downloadAnnexA1Spreadsheet(
                [
                    [
                        'sheetName' => $sheetName,
                        'cellValues' => $cellValues,
                    ],
                ],
                $filename,
                $templatePath,
            );
        }

        $sheet = $this->resolveItemReportSheet($item, $formSlug);

        return $this->templateExport->downloadFromTemplate(
            $templatePath,
            $cellValues,
            $filename,
            $sheet['sheetIndex'],
            $sheet['sheetName'],
        );
    }

    public function propertyCardFilledSpreadsheet(Item $item, Office $office, ?int $officeId): Spreadsheet
    {
        return $this->templateExport->renderFilledSpreadsheet(
            PropertyCardLayout::templatePath(),
            $this->cellValuesForPropertyCard($item, $office, $officeId),
        );
    }

    /**
     * @return Collection<int, array{item_id: int, office_id: int}>
     */
    public function stockLevelPairsForPropertyCardBulk(?int $categoryId, ?string $search): Collection
    {
        $rows = $this->stockService->getStockLevelsList();
        $user = auth()->user();

        if ($user && $user->office_id) {
            $rows = $rows->where('office_id', (int) $user->office_id)->values();
        }

        if ($categoryId !== null && $categoryId > 0) {
            $category = ItemCategory::query()->find($categoryId);
            if ($category !== null && $category->getTemplateSlug() === 'ppe') {
                $rows = $rows->where('category_name', $category->name)->values();
            } else {
                return collect();
            }
        } else {
            $rows = $rows->filter(function (object $row): bool {
                $category = ItemCategory::query()
                    ->where('name', $row->category_name ?? '')
                    ->first();

                return $category?->getTemplateSlug() === 'ppe';
            })->values();
        }

        if (filled($search)) {
            $term = mb_strtolower($search);
            $rows = $rows->filter(fn (object $row): bool => str_contains(mb_strtolower($row->item_name ?? ''), $term)
                || str_contains(mb_strtolower($row->category_name ?? ''), $term)
                || str_contains(mb_strtolower($row->office_name ?? ''), $term)
            )->values();
        }

        return $rows->map(fn (object $row): array => [
            'item_id' => (int) $row->item_id,
            'office_id' => (int) $row->office_id,
        ]);
    }

    public function downloadPropertyCardBulk(Collection $pairs): StreamedResponse
    {
        $merged = new Spreadsheet;
        $removedDefaultSheet = false;
        $usedSheetTitles = [];

        foreach ($pairs as $pair) {
            $itemId = (int) ($pair['item_id'] ?? 0);
            $officeId = (int) ($pair['office_id'] ?? 0);

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

            $source = $this->propertyCardFilledSpreadsheet($item, $office, $officeId);
            $sheet = $source->getSheet(0);

            $titleBase = filled($item->item_code) ? (string) $item->item_code : 'item_'.$item->id;
            $sheet->setTitle($this->uniqueExcelSheetTitle($titleBase, $usedSheetTitles));

            $merged->addExternalSheet($sheet);

            if (! $removedDefaultSheet) {
                $merged->removeSheetByIndex(0);
                $removedDefaultSheet = true;
            }

            $source->disconnectWorksheets();
            unset($source);
        }

        if (! $removedDefaultSheet) {
            abort(404);
        }

        $merged->setActiveSheetIndex(0);

        $writer = new Xlsx($merged);
        $downloadName = OwwaExportFilename::batch('PC');

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $downloadName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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
        /** @var array<string, array<int, array{item: Item, office: ?Office, office_id: ?int}>> $grouped */
        $grouped = [];

        foreach ($pairs as $pair) {
            $itemId = (int) ($pair['item_id'] ?? 0);
            $officeId = (int) ($pair['office_id'] ?? 0);

            if ($itemId <= 0 || $officeId <= 0) {
                continue;
            }

            $item = Item::query()->with('category')->find($itemId);
            if ($item === null || $item->category?->getTemplateSlug() !== 'semi_expendable') {
                continue;
            }

            $office = Office::query()->find($officeId);
            $propertyClass = ItemPropertyClass::resolveForExport($item->property_class);
            $grouped[$propertyClass][] = [
                'item' => $item,
                'office' => $office,
                'office_id' => $officeId,
            ];
        }

        $tabs = [];
        foreach ($grouped as $propertyClass => $entries) {
            $sheetName = ItemPropertyClass::sheetNameForForm('annex_a1', $propertyClass) ?? 'OFFICE EQUIPMENT';
            $tabs[] = [
                'sheetName' => $sheetName,
                'cellValues' => $this->cellValuesForAnnexA1Blocks($entries),
            ];
        }

        usort($tabs, fn (array $a, array $b): int => strcmp($a['sheetName'], $b['sheetName']));

        $filename = OwwaExportFilename::batch('AnnexA1');

        return $this->templateExport->downloadAnnexA1Spreadsheet($tabs, $filename);
    }

    /**
     * @return Collection<int, array{item_id: int, office_id: int}>
     */
    public function stockLevelPairsForAnnexA1Bulk(?int $categoryId, ?string $search): Collection
    {
        $rows = $this->stockService->getStockLevelsList();
        $user = auth()->user();

        if ($user && $user->office_id) {
            $rows = $rows->where('office_id', (int) $user->office_id)->values();
        }

        if ($categoryId !== null && $categoryId > 0) {
            $category = ItemCategory::query()->find($categoryId);
            if ($category !== null) {
                $rows = $rows->where('category_name', $category->name)->values();
            }
        }

        if (filled($search)) {
            $term = mb_strtolower($search);
            $rows = $rows->filter(fn (object $row): bool => str_contains(mb_strtolower($row->item_name ?? ''), $term)
                || str_contains(mb_strtolower($row->category_name ?? ''), $term)
                || str_contains(mb_strtolower($row->office_name ?? ''), $term)
            )->values();
        }

        return $rows->map(fn (object $row): array => [
            'item_id' => (int) $row->item_id,
            'office_id' => (int) $row->office_id,
        ]);
    }

    public function countStockLevelItemsMissingPropertyClass(?int $categoryId, ?string $search): int
    {
        $itemIds = $this->stockLevelPairsForAnnexA1Bulk($categoryId, $search)
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
            $sheetName = ItemPropertyClass::sheetNameForForm($formSlug, $item->property_class);

            return [
                'sheetIndex' => 0,
                'sheetName' => $sheetName,
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
        $templatePath = match ($session->count_type) {
            PhysicalCountSession::TYPE_RPCPPE => 'ppe/Recording (Stock Level)/Appendix 73 - RPCPPE.xls',
            PhysicalCountSession::TYPE_RPCSP => 'Semi-Expendable/Recording (Stock Levels)/Inventory-Annex-A.8-RPCSP - REPORT.xlsx',
            default => 'Consumable/Stock Levels & Recording/Appendix 66 - RPCI.xls',
        };

        $cellValues = $this->cellValuesForPhysicalCount($session);
        $filename = OwwaExportFilename::physicalCount(
            (string) $session->count_type,
            (string) $session->reference_code,
        );
        $sheet = $this->resolvePhysicalCountSheet($session);

        return $this->templateExport->downloadFromTemplate(
            $templatePath,
            $cellValues,
            $filename,
            $sheet['sheetIndex'],
            $sheet['sheetName'],
        );
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
        if (filled($session->property_class)) {
            return $session->property_class;
        }

        $session->loadMissing('lines.item');
        $classes = $session->lines
            ->pluck('item.property_class')
            ->filter()
            ->unique()
            ->values();

        if ($classes->count() === 1) {
            return (string) $classes->first();
        }

        if (filled($session->inventory_type_label)) {
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
            'sc' => 'Consumable/Stock Levels & Recording/Appendix 58 - SC.xls',
            'pc' => 'ppe/Accquisition/Appendix 69 - PC.xls',
            'annex_a1' => 'Semi-Expendable/Recording (Stock Levels)/Property-Form-Annex-A.1-Semi-expendable-Property-Card.xlsx',
            'annex_a4' => 'Semi-Expendable/Property-Form-Annex-A.4-Registry-of-Semi-Expendable-Property-Issued.xls',
            default => 'Consumable/Stock Levels & Recording/Appendix 58 - SC.xls',
        };
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForSc(Item $item, ?Office $office, ?int $officeId): array
    {
        $values = [
            'A6' => 'Entity Name: '.($office?->name ?? ''),
            'F6' => 'Fund Cluster: '.($office?->fund_cluster ?? ''),
            'A8' => 'Item : '.$item->name,
            'F8' => 'Stock No. : '.($item->item_code ?? ''),
            'A9' => 'Description : '.($item->description ?? ''),
            'F9' => 'Re-order Point : '.($item->reorder_level ?? 0),
            'A10' => 'Unit of Measurement : '.$item->unit,
        ];

        $startRow = 13;
        $row = $startRow;
        foreach ($this->buildTransactionHistory($item, $officeId, newestFirst: true) as $txn) {
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
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForAnnexA1(Item $item, ?Office $office, ?int $officeId): array
    {
        return $this->cellValuesForAnnexA1Block($item, $office, $officeId, 0);
    }

    /**
     * Stack one property card per item on the same sheet tab (block 0, 1, 2, …).
     *
     * @param  array<int, array{item: Item, office: ?Office, office_id: ?int}>  $entries
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForAnnexA1Blocks(array $entries): array
    {
        $values = [];

        foreach (array_values($entries) as $blockIndex => $entry) {
            $values = array_merge(
                $values,
                $this->cellValuesForAnnexA1Block(
                    $entry['item'],
                    $entry['office'],
                    $entry['office_id'],
                    $blockIndex,
                ),
            );
        }

        return $values;
    }

    /**
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForAnnexA1Block(
        Item $item,
        ?Office $office,
        ?int $officeId,
        int $blockIndex,
    ): array {
        $annexMap = OwwaCellMapping::form('ANNEX_A1');
        $ledgerStart = AnnexA1BlockLayout::ledgerStartRow($blockIndex);
        $maxRows = (int) ($annexMap['ledger']['max_rows'] ?? 11);
        $ledgerCols = (array) ($annexMap['ledger']['columns'] ?? []);
        $propertyClass = ItemPropertyClass::resolveForExport($item->property_class);

        $latestProperty = Issuance::query()
            ->where('item_id', $item->id)
            ->whereNotNull('property_number')
            ->orderByDesc('issuance_date')
            ->value('property_number');

        $values = [];
        AnnexA1BlockLayout::applyHeader($values, [
            'entity_name' => $office?->name ?? '',
            'fund_cluster' => $office?->fund_cluster ?? '',
            'property_type' => ItemPropertyClass::propertyTypeLabel($propertyClass),
            'property_number' => $latestProperty ?? $item->item_code ?? '',
            'description' => $this->itemDescription($item),
        ], $blockIndex);

        $row = $ledgerStart;
        foreach ($this->buildTransactionHistory($item, $officeId, newestFirst: true) as $txn) {
            if ($row > $ledgerStart + $maxRows - 1) {
                break;
            }

            $values[OwwaCellMapping::columnCell($ledgerCols['date'] ?? 'A', $row)] = $txn['date'] ?? '';
            $values[OwwaCellMapping::columnCell($ledgerCols['reference'] ?? 'B', $row)] = $txn['reference'] ?? '';

            if ($txn['receipt_qty']) {
                $receiptQty = (int) $txn['receipt_qty'];
                $values[OwwaCellMapping::columnCell($ledgerCols['receipt_qty'] ?? 'C', $row)] = $receiptQty;
                $values[OwwaCellMapping::columnCell($ledgerCols['receipt_qty_dup'] ?? 'F', $row)] = $receiptQty;

                if (isset($txn['unit_cost']) && $txn['unit_cost'] !== null && $txn['unit_cost'] !== '') {
                    $unitCost = (float) $txn['unit_cost'];
                    $values[OwwaCellMapping::columnCell($ledgerCols['unit_cost'] ?? 'D', $row)] = $unitCost;
                    $values[OwwaCellMapping::columnCell($ledgerCols['total_cost'] ?? 'E', $row)] = $unitCost * $receiptQty;
                }
            }

            if ($txn['issue_qty']) {
                $values[OwwaCellMapping::columnCell($ledgerCols['issue_qty'] ?? 'H', $row)] = (int) $txn['issue_qty'];
                $values[OwwaCellMapping::columnCell($ledgerCols['office_officer'] ?? 'I', $row)] = $txn['office_officer'] ?? $txn['issue_office'] ?? '';
            }

            if (filled($txn['item_code'] ?? null)) {
                $values[OwwaCellMapping::columnCell($ledgerCols['item_no'] ?? 'G', $row)] = $txn['item_code'];
            } elseif (filled($txn['property_number'] ?? null)) {
                $values[OwwaCellMapping::columnCell($ledgerCols['item_no'] ?? 'G', $row)] = $txn['property_number'];
            }

            $values[OwwaCellMapping::columnCell($ledgerCols['balance_qty'] ?? 'J', $row)] = $txn['balance'] ?? 0;
            $values[OwwaCellMapping::columnCell($ledgerCols['remarks'] ?? 'L', $row)] = $txn['remarks'] ?? '';
            $row++;
        }

        return $values;
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public function cellValuesForPropertyCard(Item $item, ?Office $office, ?int $officeId): array
    {
        $transactions = array_map(
            fn (array $txn): array => PropertyCardLayout::normalizeTransactionRow($txn),
            $this->buildTransactionHistory($item, $officeId, newestFirst: true),
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
            'L6' => 'Fund Cluster : '.($office?->fund_cluster ?? ''),
            'A7' => 'Semi-Expendable Property: '.$item->name,
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
     * @return array<string, string|int|float|null>
     */
    protected function cellValuesForPhysicalCount(PhysicalCountSession $session): array
    {
        $office = $session->office;
        $entityName = $office?->name ?? '';
        $values = match ($session->count_type) {
            PhysicalCountSession::TYPE_RPCPPE => [
                'A6' => 'Entity Name : '.$entityName,
                'C4' => $session->inventory_type_label ?? '',
                'C6' => $session->count_date?->format('Y-m-d') ?? '',
                'C9' => 'Fund Cluster : '.($session->fund_cluster ?? $office?->fund_cluster ?? ''),
                'C10' => 'For which '.$session->accountable_officer_name.' , '.$session->accountable_officer_designation.' , '.$office?->name.' , '.$session->date_of_assumption?->format('Y-m-d'),
            ],
            PhysicalCountSession::TYPE_RPCSP => [
                'A6' => 'Entity Name : '.$entityName,
                'B4' => $session->inventory_type_label ?? '',
                'B6' => $session->count_date?->format('Y-m-d') ?? '',
                'B8' => 'Fund Cluster : '.($session->fund_cluster ?? $office?->fund_cluster ?? ''),
                'B10' => 'For which '.$session->accountable_officer_name.' , '.$session->accountable_officer_designation.' , '.$office?->name.' , '.$session->date_of_assumption?->format('Y-m-d'),
            ],
            default => [
                'A6' => 'Entity Name : '.$entityName,
                'B4' => $session->inventory_type_label ?? '',
                'B6' => $session->count_date?->format('Y-m-d') ?? '',
                'B8' => 'Fund Cluster : '.($session->fund_cluster ?? $office?->fund_cluster ?? ''),
                'B10' => 'For which '.$session->accountable_officer_name.' , '.$session->accountable_officer_designation.' , '.$office?->name.' , '.$session->date_of_assumption?->format('Y-m-d'),
            ],
        };

        $startRow = match ($session->count_type) {
            PhysicalCountSession::TYPE_RPCPPE => 15,
            PhysicalCountSession::TYPE_RPCSP => 15,
            default => 15,
        };

        $row = $startRow;
        foreach ($session->lines as $line) {
            if ($row > $startRow + 20) {
                break;
            }
            $shortage = $line->shortageOverageQuantity();
            if ($session->count_type === PhysicalCountSession::TYPE_RPCPPE) {
                $values['C'.$row] = $line->article ?? $line->item?->name;
                $values['D'.$row] = $line->description ?? $line->item?->description;
                $values['E'.$row] = $line->property_number ?? $line->stock_number ?? $line->item?->item_code;
                $values['F'.$row] = $line->unit_of_measure ?? $line->item?->unit;
                $values['H'.$row] = $line->balance_per_card;
                $values['I'.$row] = $line->on_hand_count;
                $values['J'.$row] = $shortage;
                $values['L'.$row] = $line->remarks;
            } elseif ($session->count_type === PhysicalCountSession::TYPE_RPCSP) {
                $values['B'.$row] = $line->article ?? $line->item?->name;
                $values['C'.$row] = $line->description ?? $line->item?->description;
                $values['D'.$row] = $line->property_number ?? $line->stock_number ?? $line->item?->item_code;
                $values['E'.$row] = $line->unit_of_measure ?? $line->item?->unit;
                $values['G'.$row] = $line->balance_per_card;
                $values['H'.$row] = $line->on_hand_count;
                $values['I'.$row] = $shortage;
                $values['K'.$row] = $line->remarks;
            } else {
                $values['B'.$row] = $line->article ?? $line->item?->name;
                $values['C'.$row] = $line->description ?? $line->item?->description;
                $values['D'.$row] = $line->stock_number ?? $line->item?->item_code;
                $values['E'.$row] = $line->unit_of_measure ?? $line->item?->unit;
                $values['G'.$row] = $line->balance_per_card;
                $values['H'.$row] = $line->on_hand_count;
                $values['I'.$row] = $shortage;
                $values['K'.$row] = $line->remarks;
            }
            $row++;
        }

        $formCode = match ($session->count_type) {
            PhysicalCountSession::TYPE_RPCPPE => 'RPCPPE',
            PhysicalCountSession::TYPE_RPCSP => 'RPCSP',
            default => 'RPCI',
        };

        OwwaCellMapping::applySignatures($values, $formCode, [
            'certified_by' => $session->certified_by_printed_name ?? '',
            'approved_by' => $session->approved_by_printed_name ?? '',
            'verified_by' => $session->verified_by_printed_name ?? '',
        ]);

        return $values;
    }

    protected function itemDescription(?Item $item): string
    {
        if ($item === null) {
            return '';
        }

        $parts = array_filter([$item->name, $item->description, $item->serial_number ? 'S/N: '.$item->serial_number : null]);

        return implode(' — ', $parts);
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
