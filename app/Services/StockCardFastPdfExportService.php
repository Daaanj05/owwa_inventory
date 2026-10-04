<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Office;
use App\Support\OwwaExportFilename;
use App\Support\StockCardLedgerDateRange;
use App\Support\UnitCostKey;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class StockCardFastPdfExportService
{
    public const string DISK = StockCardQueuedExportService::DISK;

    public const string DIRECTORY = StockCardQueuedExportService::DIRECTORY;

    public function __construct(
        protected OwwaItemReportService $itemReport,
    ) {}

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost?: float|null}>  $pairs
     */
    public function download(
        Collection $pairs,
        bool $previewHtml = false,
        ?CarbonInterface $dateFrom = null,
        ?CarbonInterface $dateTo = null,
    ): Response {
        $cards = $this->buildCards($pairs, $dateFrom, $dateTo);

        abort_if($cards === [], 404, 'No matching stock cards could be built for the selected positions.');

        if ($previewHtml) {
            return response()->view('reports.stock-card-fast', [
                'cards' => $cards,
                'entityName' => (string) config('owwa_export_standards.entity_name', 'OWWA-4A'),
                'forDomPdf' => false,
                'showGeneratedOn' => false,
            ]);
        }

        $filename = OwwaExportFilename::batch('SC-fast', ext: 'pdf');

        return $this->makePdf($cards)->download($filename);
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost?: float|null}>  $pairs
     * @return array<int, array{
     *     item_name: string,
     *     item_code: string,
     *     description: string,
     *     unit: string,
     *     reorder_level: int|float|string|null,
     *     days_to_consume: int|float|string|null,
     *     office_name: string,
     *     transactions: array<int, array<string, mixed>>
     * }>
     */
    public function buildCards(
        Collection $pairs,
        ?CarbonInterface $dateFrom = null,
        ?CarbonInterface $dateTo = null,
    ): array {
        $normalized = $pairs
            ->map(function (array $pair): ?array {
                $itemId = (int) ($pair['item_id'] ?? 0);
                $officeId = (int) ($pair['office_id'] ?? 0);
                if ($itemId <= 0 || $officeId <= 0) {
                    return null;
                }

                return [
                    'item_id' => $itemId,
                    'office_id' => $officeId,
                    'unit_cost' => array_key_exists('unit_cost', $pair)
                        ? ($pair['unit_cost'] !== null ? (float) $pair['unit_cost'] : null)
                        : null,
                ];
            })
            ->filter()
            ->values();

        if ($normalized->isEmpty()) {
            return [];
        }

        $itemsById = Item::query()
            ->with('category')
            ->whereIn('id', $normalized->pluck('item_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $officesById = Office::query()
            ->whereIn('id', $normalized->pluck('office_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $resolved = $normalized
            ->map(function (array $pair) use ($itemsById, $officesById): ?array {
                $item = $itemsById->get($pair['item_id']);
                $office = $officesById->get($pair['office_id']);
                if ($item === null || $office === null || $item->category?->getTemplateSlug() !== 'consumables') {
                    return null;
                }

                return [
                    ...$pair,
                    'item' => $item,
                    'office' => $office,
                ];
            })
            ->filter()
            ->values();

        if ($resolved->isEmpty()) {
            return [];
        }

        $itemIds = $resolved->pluck('item_id')->unique()->values()->all();
        $officeIds = $resolved->pluck('office_id')->unique()->values()->all();
        $itemsForHistory = $resolved->pluck('item', 'item_id');

        $historiesByCostKey = [];
        foreach ($resolved->groupBy(fn (array $pair): string => UnitCostKey::normalize($pair['unit_cost'])) as $costKey => $group) {
            $unitCost = $group->first()['unit_cost'] ?? null;
            $historiesByCostKey[$costKey] = $this->itemReport->buildTransactionHistoriesForItems(
                $itemIds,
                $officeIds,
                true,
                $itemsForHistory,
                $unitCost,
            );
        }

        $cards = [];
        foreach ($resolved as $pair) {
            /** @var Item $item */
            $item = $pair['item'];
            /** @var Office $office */
            $office = $pair['office'];
            $costKey = UnitCostKey::normalize($pair['unit_cost']);
            $itemHistory = $historiesByCostKey[$costKey][$item->id] ?? [];
            $transactions = array_values(array_filter(
                $itemHistory,
                fn (array $txn): bool => (int) ($txn['office_id'] ?? 0) === (int) $pair['office_id'],
            ));

            if ($dateFrom !== null && $dateTo !== null) {
                $transactions = StockCardLedgerDateRange::applyApproachB(
                    $transactions,
                    $dateFrom,
                    $dateTo,
                    newestFirst: true,
                );
            }

            $cards[] = [
                'item_name' => (string) $item->name,
                'item_code' => (string) ($item->item_code ?? ''),
                'description' => (string) ($item->description ?? ''),
                'unit' => (string) ($item->unit ?? ''),
                'reorder_level' => $item->reorder_level ?? 0,
                'days_to_consume' => $item->days_to_consume ?? '',
                'office_name' => (string) $office->name,
                'transactions' => $transactions,
            ];
        }

        return $cards;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    public function pdfBinary(array $cards): string
    {
        return $this->makePdf($cards)->output();
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    protected function makePdf(array $cards): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('reports.stock-card-fast', [
            'cards' => $cards,
            'entityName' => (string) config('owwa_export_standards.entity_name', 'OWWA-4A'),
            'forDomPdf' => true,
            'showGeneratedOn' => true,
            'generatedOn' => now()->timezone(config('app.timezone'))->format('Y-m-d H:i'),
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isFontSubsettingEnabled', true);
    }
}
