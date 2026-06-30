<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
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
     *     header: array<string, string|null>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    public function present(Item $item, Office $office): array
    {
        $item->loadMissing('category');
        $slug = $item->category?->getTemplateSlug() ?? 'consumables';
        $config = $this->categoryConfig($slug);

        $history = $this->itemReport->buildTransactionHistory($item, $office->id, newestFirst: true);
        $rows = array_map(
            fn (array $txn): array => $this->mapRow($txn, $item, $slug),
            $history,
        );

        return [
            'title' => $config['title'],
            'exportForm' => $config['exportForm'],
            'exportLabel' => $config['exportLabel'],
            'exportUrl' => route('owwa.export.item', $item).'?form='.urlencode($config['exportForm']).'&office_id='.$office->id,
            'header' => $this->buildHeader($item, $office, $slug),
            'columns' => $config['columns'],
            'rows' => $rows,
        ];
    }

    /**
     * @param  Collection<int, object>  $visibleRows
     */
    public function assertVisibleInStockList(int $itemId, int $officeId, Collection $visibleRows): void
    {
        $visible = $visibleRows->contains(
            fn (object $row): bool => (int) ($row->item_id ?? 0) === $itemId
                && (int) ($row->office_id ?? 0) === $officeId,
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
                'exportLabel' => 'Export Property Card (XLS)',
                'columns' => $propertyColumns,
            ],
            'semi_expendable' => [
                'title' => 'Semi-Expendable Property Card (Annex A.1)',
                'exportForm' => 'annex_a1',
                'exportLabel' => 'Export Property Card (XLS)',
                'columns' => $propertyColumns,
            ],
            default => [
                'title' => 'Stock Card (Appendix 58)',
                'exportForm' => 'sc',
                'exportLabel' => 'Export Stock Card (XLS)',
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
    protected function buildHeader(Item $item, Office $office, string $slug): array
    {
        $base = [
            'entity_name' => $office->name,
            'fund_cluster' => $office->fund_cluster,
            'item_name' => $item->name,
            'description' => $item->description,
        ];

        if ($slug === 'consumables') {
            return [
                ...$base,
                'stock_no' => $item->item_code,
                'reorder_level' => (string) ($item->reorder_level ?? 0),
                'unit' => $item->unit,
            ];
        }

        $propertyNumber = Issuance::query()
            ->where('item_id', $item->id)
            ->whereNotNull('property_number')
            ->orderByDesc('issuance_date')
            ->value('property_number');

        return [
            ...$base,
            'property_number' => $propertyNumber ?? $item->item_code,
        ];
    }

    /**
     * @param  array<string, mixed>  $txn
     * @return array<string, mixed>
     */
    protected function mapRow(array $txn, Item $item, string $slug): array
    {
        $row = [
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
        ];

        return $row;
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
