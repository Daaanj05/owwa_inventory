<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Office;
use App\Support\ItemPropertyClass;
use App\Support\OwwaExportFilename;
use App\Support\PdfSafeText;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockCardPdfExportService
{
    public function __construct(
        protected StockLedgerViewService $ledgerView,
    ) {}

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>  $pairs
     */
    public function downloadMerged(Collection $pairs, string $categorySlug): Response|StreamedResponse
    {
        $cards = $this->buildCards($pairs, $categorySlug);

        abort_if($cards === [], 404);

        $formCode = match ($categorySlug) {
            'ppe' => 'PC',
            'semi_expendable' => 'AnnexA1',
            default => 'SC',
        };

        $generatedAt = now();

        // OWWA SC / PC / Annex A.1 ledgers are wide landscape forms.
        $pdf = Pdf::loadView('owwa.stock-cards-pdf', [
            'cards' => $cards,
            'categorySlug' => $categorySlug,
            'generatedAt' => $generatedAt,
        ])->setPaper('legal', 'landscape');

        return $pdf->download(OwwaExportFilename::batch($formCode, ext: 'pdf'));
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>  $pairs
     * @return array<int, array{
     *     slug: string,
     *     appendix: string,
     *     title: string,
     *     header: array<string, string|null>,
     *     rows: array<int, array<string, mixed>>
     * }>
     */
    public function buildCards(Collection $pairs, string $categorySlug): array
    {
        $cards = [];

        foreach ($pairs as $pair) {
            $item = Item::query()->with('category')->find($pair['item_id']);
            $office = Office::query()->find($pair['office_id']);

            if ($item === null || $office === null) {
                continue;
            }

            if (($item->category?->getTemplateSlug() ?? 'consumables') !== $categorySlug) {
                continue;
            }

            $present = $this->ledgerView->present(
                $item,
                $office,
                $pair['unit_cost'] ?? null,
            );

            $header = $present['header'];
            if ($categorySlug === 'semi_expendable') {
                $header['property_type'] = ItemPropertyClass::propertyTypeLabel(
                    ItemPropertyClass::resolveForExport($item->property_class),
                );
            }
            if ($categorySlug === 'ppe') {
                $header['property_type'] = $item->category?->name ?? 'Property, Plant and Equipment';
            }

            $cards[] = [
                'slug' => $categorySlug,
                'appendix' => match ($categorySlug) {
                    'ppe' => 'Appendix 69',
                    'semi_expendable' => 'Annex A.1',
                    default => 'Appendix 58',
                },
                'title' => match ($categorySlug) {
                    'ppe' => 'PROPERTY CARD',
                    'semi_expendable' => 'SEMI-EXPENDABLE PROPERTY CARD',
                    default => 'STOCK CARD',
                },
                'header' => PdfSafeText::normalizeArray($header),
                'rows' => array_map(
                    fn (array $row): array => PdfSafeText::normalizeArray($row),
                    $this->normalizeRowsForForm($present['rows'], $categorySlug),
                ),
            ];
        }

        return $cards;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeRowsForForm(array $rows, string $categorySlug): array
    {
        return array_map(function (array $row) use ($categorySlug): array {
            $receiptQty = $row['receipt_qty'] ?? null;
            $unitCost = $row['unit_cost'] ?? null;
            $amount = $row['amount'] ?? null;

            if ($amount === null && $receiptQty !== null && $unitCost !== null) {
                $amount = round((float) $unitCost * (int) $receiptQty, 2);
            }

            $normalized = [
                'date' => $row['date'] ?? '',
                'reference' => $row['reference'] ?? '',
                'receipt_qty' => $receiptQty,
                'issue_qty' => $row['issue_qty'] ?? null,
                'balance' => $row['balance'] ?? 0,
                'remarks' => $row['remarks'] ?? '',
            ];

            if ($categorySlug === 'consumables') {
                $normalized['issue_office'] = $row['issue_office'] ?? '';
                $normalized['days_to_consume'] = $row['days_to_consume'] ?? '';
            } else {
                $normalized['office_officer'] = $row['office_officer'] ?? $row['issue_office'] ?? '';
                $normalized['amount'] = $amount;
            }

            if ($categorySlug === 'semi_expendable') {
                $normalized['unit_cost'] = $unitCost;
                $normalized['total_cost'] = $amount;
                $normalized['item_no'] = $row['property_number'] ?? '';
            }

            return $normalized;
        }, $rows);
    }
}
