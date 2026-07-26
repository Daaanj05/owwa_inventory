<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Support\OwwaReferenceLabels;
use App\Support\UnitCostKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class StockLedgerViewService
{
    public function __construct(
        protected OwwaItemReportService $itemReport,
    ) {}

    /**
     * @return array{
     *     title: string,
     *     exportForm: string,
     *     exportLabel: string,
     *     exportUrl: string,
     *     exportPdfLabel: string,
     *     exportPdfUrl: string,
     *     header: array<string, string|null>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>,
     *     unit_cost: float|null
     * }
     */
    /**
     * Lightweight export metadata (no transaction history). Safe for Livewire props / footer actions.
     *
     * @return array{
     *     title: string,
     *     exportLabel: string,
     *     exportUrl: string,
     *     exportPdfLabel: string,
     *     exportPdfUrl: string
     * }
     */
    public function exportLinks(Item $item, Office $office, ?float $unitCost = null): array
    {
        $item->loadMissing('category');
        $slug = $item->category?->getTemplateSlug() ?? 'consumables';
        $config = $this->categoryConfig($slug);

        $pairKey = app(StockLevelExportService::class)->encodePairKey(
            (int) $item->id,
            (int) $office->id,
            $unitCost,
        );

        // Same bulk stock-cards route as the toolbar export (one pair).
        $exportUrl = route('owwa.export.bulk.stock-cards', array_filter([
            'category' => $item->item_category_id,
            'pairs' => $pairKey,
        ], fn (mixed $value): bool => filled($value)));

        $exportPdfUrl = route('owwa.export.bulk.stock-cards', array_filter([
            'category' => $item->item_category_id,
            'format' => 'pdf',
            'pairs' => $pairKey,
        ], fn (mixed $value): bool => filled($value)));

        return [
            'title' => $config['title'],
            'exportLabel' => $config['exportLabel'],
            'exportUrl' => $exportUrl,
            'exportPdfLabel' => $config['exportPdfLabel'],
            'exportPdfUrl' => $exportPdfUrl,
        ];
    }

    public function present(Item $item, Office $office, ?float $unitCost = null): array
    {
        $item->loadMissing('category');
        $slug = $item->category?->getTemplateSlug() ?? 'consumables';
        $config = $this->categoryConfig($slug);
        $links = $this->exportLinks($item, $office, $unitCost);

        $history = $this->itemReport->buildTransactionHistory($item, $office->id, newestFirst: true, unitCost: $unitCost);
        $rows = array_map(
            fn (array $txn): array => $this->mapRow($txn, $item, $slug),
            $history,
        );

        return [
            'title' => $links['title'],
            'exportForm' => $config['exportForm'],
            'exportLabel' => $links['exportLabel'],
            'exportUrl' => $links['exportUrl'],
            'exportPdfLabel' => $links['exportPdfLabel'],
            'exportPdfUrl' => $links['exportPdfUrl'],
            'header' => $this->buildHeader($item, $office, $slug, $unitCost),
            'columns' => $config['columns'],
            'rows' => $rows,
            'unit_cost' => $unitCost,
        ];
    }

    /**
     * @param  Collection<int, object>  $visibleRows
     */
    public function assertVisibleInStockList(
        int $itemId,
        int $officeId,
        Collection $visibleRows,
        ?float $unitCost = null,
    ): void {
        $visible = $visibleRows->contains(
            fn (object $row): bool => (int) ($row->item_id ?? 0) === $itemId
                && (int) ($row->office_id ?? 0) === $officeId
                && ($unitCost === null || UnitCostKey::equals(
                    isset($row->avg_unit_cost)
                        ? (float) $row->avg_unit_cost
                        : (isset($row->unit_cost) ? (float) $row->unit_cost : null),
                    $unitCost,
                ) || UnitCostKey::equals(
                    isset($row->unit_cost) ? (float) $row->unit_cost : null,
                    $unitCost,
                )),
        );

        if (! $visible) {
            throw new AuthorizationException('This item is not visible in your stock levels list.');
        }
    }

    /**
     * @return array{
     *     title: string,
     *     exportForm: string,
     *     exportLabel: string,
     *     exportPdfLabel: string,
     *     columns: array<string, string>
     * }
     */
    protected function categoryConfig(string $slug): array
    {
        $propertyColumns = [
            'date' => 'Date',
            'reference' => 'Reference',
            'type_label' => 'Type',
            'receipt_qty' => 'Receipt',
            'issue_qty' => 'Issue',
            'office_officer' => 'Office / Officer',
            'balance' => 'Balance',
            'remarks' => 'Remarks',
        ];

        return match ($slug) {
            'ppe' => [
                'title' => 'Property Card (Appendix 69)',
                'exportForm' => 'pc',
                'exportLabel' => 'Export Property Card (Excel)',
                'exportPdfLabel' => 'Export Property Card (PDF)',
                'columns' => $propertyColumns,
            ],
            'semi_expendable' => [
                'title' => 'Semi-Expendable Property Card (Annex A.1)',
                'exportForm' => 'annex_a1',
                'exportLabel' => 'Export Annex A.1 (Excel)',
                'exportPdfLabel' => 'Export Annex A.1 (PDF)',
                'columns' => $propertyColumns,
            ],
            default => [
                'title' => 'Stock Card (Appendix 58)',
                'exportForm' => 'sc',
                'exportLabel' => 'Export Stock Card (Excel)',
                'exportPdfLabel' => 'Export Stock Card (PDF)',
                'columns' => [
                    'date' => 'Date',
                    'reference' => 'Reference',
                    'type_label' => 'Type',
                    'receipt_qty' => 'Receipt',
                    'issue_qty' => 'Issue',
                    'issue_office' => 'Issue office',
                    'balance' => 'Balance',
                    'days_to_consume' => 'Days to consume',
                ],
            ],
        };
    }

    /**
     * @return array<string, string|null>
     */
    protected function buildHeader(Item $item, Office $office, string $slug, ?float $unitCost = null): array
    {
        $base = [
            'entity_name' => $office->name,
            'fund_cluster' => '',
            'item_name' => $item->name,
            'description' => $item->description,
        ];

        if ($slug === 'consumables') {
            return [
                ...$base,
                'stock_no' => $item->item_code,
                'reorder_level' => (string) ($item->reorder_level ?? 0),
                'unit' => $item->unit,
                'unit_cost' => $unitCost !== null ? '₱'.number_format($unitCost, 2) : null,
            ];
        }

        return [
            ...$base,
            'category_slug' => $slug,
            'asset_identifier_label' => OwwaReferenceLabels::assetIdentifierHeaderLabel($slug),
            'property_number' => $this->resolveAccountablePropertyNumber($item, $slug, $unitCost) ?? '—',
            'unit_cost' => $unitCost !== null ? '₱'.number_format($unitCost, 2) : null,
        ];
    }

    protected function resolveAccountablePropertyNumber(Item $item, string $slug, ?float $unitCost = null): ?string
    {
        if ($slug === 'semi_expendable') {
            return $item->resolvedSemiExpendablePropertyNumber($unitCost);
        }

        $fromUnit = InventoryUnit::query()
            ->where('item_id', $item->id)
            ->whereNotNull('property_number')
            ->orderByDesc('id')
            ->value('property_number');

        if (filled($fromUnit)) {
            return (string) $fromUnit;
        }

        $fromIssuance = Issuance::query()
            ->where('item_id', $item->id)
            ->whereNotNull('property_number')
            ->orderByDesc('issuance_date')
            ->value('property_number');

        return filled($fromIssuance) ? (string) $fromIssuance : null;
    }

    /**
     * @param  array<string, mixed>  $txn
     * @return array<string, mixed>
     */
    protected function mapRow(array $txn, Item $item, string $slug): array
    {
        return [
            'date' => $txn['date'] ?? '',
            'reference' => $txn['reference'] ?? '',
            'type_label' => $this->typeLabel((string) ($txn['type'] ?? '')),
            'receipt_qty' => filled($txn['receipt_qty'] ?? null) ? (int) $txn['receipt_qty'] : null,
            'issue_qty' => filled($txn['issue_qty'] ?? null) ? (int) $txn['issue_qty'] : null,
            'issue_office' => $txn['issue_office'] ?? null,
            'office_officer' => $txn['office_officer'] ?? $txn['issue_office'] ?? null,
            'balance' => $txn['balance'] ?? 0,
            'remarks' => $txn['remarks'] ?? null,
            'days_to_consume' => $slug === 'consumables' ? ($item->days_to_consume ?? null) : null,
            'unit_cost' => isset($txn['unit_cost']) && $txn['unit_cost'] !== null && $txn['unit_cost'] !== ''
                ? (float) $txn['unit_cost']
                : null,
            'amount' => isset($txn['amount']) && $txn['amount'] !== null && $txn['amount'] !== ''
                ? (float) $txn['amount']
                : null,
            'property_number' => $txn['property_number'] ?? $txn['item_code'] ?? null,
        ];
    }

    protected function typeLabel(string $type): string
    {
        return match ($type) {
            'receipt' => 'Receipt',
            'issue' => 'Issue',
            'transfer_in' => 'Transfer in',
            'transfer_out' => 'Transfer out',
            'disposal' => 'Disposal',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
