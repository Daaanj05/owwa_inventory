<?php

namespace App\Services;

use App\Models\Issuance;
use App\Support\OwwaCellMapping;
use App\Support\OwwaExportFilename;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fast Appendix 59 ICS PDF: Blade + DomPDF lookalike for semi-expendable issuances.
 */
class IcsFastPdfExportService
{
    public function download(Issuance $issuance): Response
    {
        $issuance->loadMissing([
            'item.category',
            'office',
            'department',
            'issuedBy.office',
            'issuedTo.office',
            'issuedTo.department',
            'requisition',
            'consolidatedRequisition',
            'batch.lines.item',
            'batch.lines.office',
            'batch.lines.department',
            'batch.lines.issuedBy',
            'batch.lines.issuedTo',
            'batch.issuedTo',
        ]);

        $pages = $this->buildPages($issuance->batchLines());

        $filename = OwwaExportFilename::transaction(
            'ICS-fast',
            $issuance->controlNumber() ?? (string) $issuance->getKey(),
            'pdf',
        );

        return $this->makePdf($pages)->download($filename);
    }

    /**
     * @param  Collection<int, Issuance>  $issuances
     * @return array<int, array<string, mixed>>
     */
    public function buildPages(Collection $issuances): array
    {
        $issuances = $issuances->sortBy('id')->values();

        if ($issuances->isEmpty()) {
            return [];
        }

        $issuances->each(function (Issuance $issuance): void {
            $issuance->loadMissing([
                'item',
                'office',
                'department',
                'issuedBy.office',
                'issuedTo.office',
                'issuedTo.department',
                'batch.issuedTo',
                'consolidatedRequisition.requestedBy',
                'requisition',
            ]);
        });

        $first = $issuances->first();
        $maxRows = $this->maxDetailRows();
        abort_if(
            $issuances->count() > $maxRows,
            422,
            'Too many issuance lines for one ICS form (max '.$maxRows.').',
        );

        $lines = $issuances->map(function (Issuance $issuance): array {
            $item = $issuance->item;
            $unitCost = $issuance->unit_cost !== null ? (float) $issuance->unit_cost : null;
            $totalCost = $issuance->amount !== null
                ? (float) $issuance->amount
                : ($unitCost !== null ? $unitCost * (int) ($issuance->quantity ?? 1) : null);
            $usefulLife = $issuance->estimated_useful_life ?? $item?->estimated_useful_life ?? '';

            return [
                'quantity' => (string) $issuance->quantity,
                'unit' => (string) ($item?->unit ?? ''),
                'unit_cost' => $unitCost !== null ? number_format($unitCost, 2) : '',
                'total_cost' => $totalCost !== null ? number_format($totalCost, 2) : '',
                'description' => $this->formatItemDescription($item),
                'inventory_item_no' => $issuance->issuedPropertyNumbersLabel() !== ''
                    ? $issuance->issuedPropertyNumbersLabel()
                    : (string) ($item?->item_code ?? ''),
                'estimated_useful_life' => (string) $usefulLife,
            ];
        })->values()->all();

        $office = $first->office;
        $batch = $first->batch;
        $receivedByUser = $this->paperworkReceivedBy($first);

        $receivedFromName = (string) (
            $batch?->received_from_name
            ?? $first->received_from_name
            ?? $batch?->custodian_printed_name
            ?? $first->custodian_printed_name
            ?? $first->issuedBy?->name
            ?? ''
        );
        $receivedByName = (string) (
            $batch?->received_by_printed_name
            ?? $first->received_by_printed_name
            ?? $receivedByUser?->name
            ?? ''
        );
        $receivedFromPosition = (string) (
            $batch?->custodian_designation
            ?? $first->custodian_designation
            ?? $office?->supply_custodian_designation
            ?? $first->issuedBy?->office?->name
            ?? $office?->name
            ?? ''
        );
        $receivedByPosition = (string) (
            $batch?->issued_to_designation
            ?? $first->issued_to_designation
            ?? $receivedByUser?->department?->name
            ?? $first->department?->name
            ?? $receivedByUser?->office?->name
            ?? ''
        );

        $first->loadMissing(['consolidatedRequisition', 'requisition']);
        $slip = $first->consolidatedRequisition ?? $first->requisition;
        $date = $first->issuance_date?->format('Y-m-d') ?? '';

        return [[
            'entity_name' => (string) config('owwa_export_standards.entity_name'),
            'fund_cluster' => '',
            'ics_no' => $this->formatControlNumber(
                $first->controlNumber(),
                $first->issuance_date?->format('Y-m-d'),
            ),
            'lines' => $lines,
            'received_from_name' => $receivedFromName,
            'received_by_name' => $receivedByName,
            'received_from_position' => $receivedFromPosition,
            'received_by_position' => $receivedByPosition,
            'received_from_date' => $slip?->ris_issued_by_date?->format('Y-m-d') ?? $date,
            'received_by_date' => $slip?->ris_received_by_date?->format('Y-m-d') ?? $date,
            'min_detail_rows' => max($this->minDetailRows(), count($lines)),
        ]];
    }

    public function minDetailRows(): int
    {
        return (int) (OwwaCellMapping::form('ICS')['detail']['template_detail_rows'] ?? 30);
    }

    public function maxDetailRows(): int
    {
        return (int) (OwwaCellMapping::form('ICS')['detail']['max_rows'] ?? 200);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function renderHtml(array $pages, bool $forDomPdf = false, bool $showGeneratedOn = false): string
    {
        return view('reports.ics-fast', [
            'pages' => $pages,
            'minRows' => $this->minDetailRows(),
            'forDomPdf' => $forDomPdf,
            'showGeneratedOn' => $showGeneratedOn,
            'generatedOn' => now()->timezone(config('app.timezone'))->format('Y-m-d H:i'),
        ])->render();
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function pdfBinary(array $pages): string
    {
        return $this->makePdf($pages)->output();
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    protected function makePdf(array $pages): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('reports.ics-fast', [
            'pages' => $pages,
            'minRows' => $this->minDetailRows(),
            'forDomPdf' => true,
            'showGeneratedOn' => true,
            'generatedOn' => now()->timezone(config('app.timezone'))->format('Y-m-d H:i'),
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isFontSubsettingEnabled', true);
    }

    protected function formatControlNumber(?string $candidate, ?string $date): string
    {
        $normalized = strtoupper(trim((string) ($candidate ?? '')));
        if (preg_match('/^\d{4}-\d{2}-\d{4}$/', $normalized) === 1) {
            return $normalized;
        }

        $parsedDate = filled($date) ? Carbon::parse($date) : now();
        $year = $parsedDate->format('Y');
        preg_match_all('/\d+/', $normalized, $numericParts);
        $seed = implode('', $numericParts[0] ?? []);
        $serial = $seed !== '' ? ((int) substr($seed, -4)) : 0;
        if ($serial <= 0) {
            $serial = ((int) $parsedDate->format('dHis')) % 10000;
        }

        return $year.'-01-'.str_pad((string) $serial, 4, '0', STR_PAD_LEFT);
    }

    protected function formatItemDescription(?\App\Models\Item $item): string
    {
        if ($item === null) {
            return '';
        }

        $parts = array_filter([$item->name, $item->description]);

        return implode(' — ', $parts);
    }

    protected function paperworkReceivedBy(Issuance $issuance): ?\App\Models\User
    {
        $issuance->loadMissing(['batch.issuedTo', 'consolidatedRequisition.requestedBy', 'issuedTo']);

        if ($issuance->consolidated_requisition_id) {
            return $issuance->consolidatedRequisition?->requestedBy;
        }

        return $issuance->batch?->issuedTo ?? $issuance->issuedTo;
    }
}
