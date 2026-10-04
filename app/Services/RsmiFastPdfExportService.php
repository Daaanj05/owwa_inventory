<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\Issuance;
use App\Support\OwwaCellMapping;
use App\Support\OwwaExportFilename;
use App\Support\PhpExtensionGuard;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class RsmiFastPdfExportService
{
    public const string DISK = StockCardQueuedExportService::DISK;

    public const string DIRECTORY = 'exports/rsmi-fast';

    public function download(Issuance $issuance): Response
    {
        $issuance->loadMissing([
            'item.category',
            'office',
            'department',
            'requisition',
            'consolidatedRequisition',
            'batch.lines.item',
            'batch.lines.office',
            'batch.lines.department',
            'batch.lines.requisition',
            'batch.lines.consolidatedRequisition',
            'issuedBy',
        ]);

        $lines = $issuance->batchLines();
        $pages = $this->buildPages($lines);

        $filename = OwwaExportFilename::transaction(
            'RSMI-fast',
            $issuance->controlNumber() ?? (string) $issuance->getKey(),
            'pdf',
        );

        return $this->makePdf($pages)->download($filename);
    }

    /**
     * @param  Collection<int, Issuance>  $issuances
     */
    public function downloadMany(Collection $issuances): Response
    {
        $pages = $this->buildPages($issuances);

        abort_if($pages === [], 404, 'No issuances could be built for the selected date range.');

        return $this->makePdf($pages)->download(OwwaExportFilename::batch('RSMI-fast', ext: 'pdf'));
    }

    /**
     * Sync ZIP of Fast RSMI PDFs (BATCH_SIZE lines each).
     *
     * @param  Collection<int, Issuance>  $issuances
     */
    public function downloadZip(Collection $issuances): BinaryFileResponse
    {
        PhpExtensionGuard::ensureZipArchive();

        $issuances = $issuances->sortBy([
            ['issuance_date', 'asc'],
            ['id', 'asc'],
        ])->values();
        abort_if($issuances->isEmpty(), 404, 'No issuances could be built for the selected date range.');

        $chunkSize = StockLevelExportService::BATCH_SIZE;
        $chunks = $issuances->chunk($chunkSize)->values();
        $zipFilename = OwwaExportFilename::batch('RSMI-fast', ext: 'zip');

        $userId = (int) (auth()->id() ?: 0);
        $relativeDir = self::DIRECTORY.'/'.$userId;
        Storage::disk(self::DISK)->makeDirectory($relativeDir);
        $relativePath = $relativeDir.'/'.Str::uuid().'-'.$zipFilename;
        $absoluteZip = Storage::disk(self::DISK)->path($relativePath);

        $zip = new ZipArchive;
        if ($zip->open($absoluteZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create Fast RSMI export ZIP.');
        }

        try {
            foreach ($chunks as $index => $chunk) {
                $binary = $this->pdfBinary($this->buildPages($chunk->values()));
                $zip->addFromString(sprintf('part-%02d.pdf', $index + 1), $binary);
                unset($binary);
                gc_collect_cycles();
            }
        } finally {
            $zip->close();
        }

        return response()
            ->download($absoluteZip, $zipFilename, [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * @param  Collection<int, Issuance>  $issuances
     * @return array<int, array<string, mixed>>
     */
    public function buildPages(Collection $issuances): array
    {
        $issuances = $issuances->sortBy([
            ['issuance_date', 'asc'],
            ['id', 'asc'],
        ])->values();

        if ($issuances->isEmpty()) {
            return [];
        }

        $issuances->each(function (Issuance $issuance): void {
            $issuance->loadMissing([
                'requisition',
                'consolidatedRequisition',
                'department',
                'item',
                'office',
                'batch',
                'issuedBy',
            ]);
        });

        $first = $issuances->first();
        $entityName = (string) config('owwa_export_standards.entity_name', 'OWWA-4A');
        $reportDate = $issuances
            ->map(fn (Issuance $issuance): ?string => $issuance->issuance_date?->format('Y-m-d'))
            ->filter()
            ->sort()
            ->last() ?? now()->format('Y-m-d');

        $serial = $this->formatControlNumber(
            $first->controlNumber(),
            $first->issuance_date?->format('Y-m-d'),
        );

        $maxRows = $this->maxDetailRows();
        abort_if(
            $issuances->count() > $maxRows,
            422,
            'Too many issuance lines for one RSMI form (max '.$maxRows.'). Narrow the date range.',
        );

        $lines = $issuances->map(function (Issuance $issuance): array {
            $unitCost = $this->resolveUnitCost($issuance);
            $quantity = (int) $issuance->quantity;
            $lineAmount = $this->resolveLineAmount($issuance, $unitCost);
            $lineOffice = $issuance->office;
            $responsibilityCenter = $issuance->department?->code
                ?? $lineOffice?->code
                ?? $lineOffice?->name
                ?? '';

            return [
                'ris_no' => $this->risNumber($issuance),
                'responsibility_center' => $responsibilityCenter,
                'stock_no' => (string) ($issuance->item?->item_code ?? ''),
                'item' => (string) ($issuance->item?->name ?? ''),
                'unit' => (string) ($issuance->item?->unit ?? ''),
                'quantity' => (string) $quantity,
                'unit_cost' => $unitCost !== null ? number_format($unitCost, 2) : '',
                'amount' => $lineAmount !== null ? number_format($lineAmount, 2) : '',
            ];
        })->values()->all();

        $recapLines = array_map(static function (array $line): array {
            return [
                'stock_no' => $line['stock_no'],
                'quantity' => $line['quantity'],
                'unit_cost' => $line['unit_cost'],
                'total_cost' => $line['amount'],
                'uacs' => '',
            ];
        }, $lines);

        $custodianName = (string) (
            $first->batch?->custodian_printed_name
            ?? $first->custodian_printed_name
            ?? $first->issuedBy?->name
            ?? ''
        );
        $accountingName = (string) (
            $first->batch?->accounting_staff_printed_name
            ?? $first->accounting_staff_printed_name
            ?? ''
        );

        // One growing form (official Excel inserts rows past the 21-row template block).
        return [[
            'entity_name' => $entityName,
            'fund_cluster' => '',
            'serial_no' => $serial,
            'date' => $reportDate,
            'lines' => $lines,
            'recap_lines' => $recapLines,
            'custodian_name' => $custodianName,
            'accounting_staff_name' => $accountingName,
            'posted_date' => $reportDate,
            'include_footer' => true,
            'min_detail_rows' => max($this->minDetailRows(), count($lines)),
            'min_recap_rows' => max($this->minRecapRows(), count($recapLines)),
        ]];
    }

    /** Minimum blank detail block (official template size). Form grows when lines exceed this. */
    public function minDetailRows(): int
    {
        return (int) (OwwaCellMapping::form('RSMI')['detail']['template_detail_rows'] ?? 21);
    }

    public function maxDetailRows(): int
    {
        return (int) (OwwaCellMapping::form('RSMI')['detail']['max_rows'] ?? 200);
    }

    public function minRecapRows(): int
    {
        $detail = (array) (OwwaCellMapping::form('RSMI')['detail'] ?? []);
        $start = (int) ($detail['recap_start_row'] ?? 36);
        $end = (int) ($detail['recap_end_row'] ?? 51);

        return max(1, ($end - $start) + 1);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function renderHtml(array $pages, bool $forDomPdf = false, bool $showGeneratedOn = false): string
    {
        return view('reports.rsmi-fast', [
            'pages' => $pages,
            'minRows' => $this->minDetailRows(),
            'minRecapRows' => $this->minRecapRows(),
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
        return Pdf::loadView('reports.rsmi-fast', [
            'pages' => $pages,
            'minRows' => $this->minDetailRows(),
            'minRecapRows' => $this->minRecapRows(),
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
        $month = $parsedDate->format('m');

        preg_match_all('/\d+/', $normalized, $numericParts);
        $seed = implode('', $numericParts[0] ?? []);
        $serial = $seed !== '' ? ((int) substr($seed, -4)) : 0;
        if ($serial <= 0) {
            $serial = ((int) $parsedDate->format('dHis')) % 10000;
        }

        $serialPart = str_pad((string) $serial, 4, '0', STR_PAD_LEFT);

        return "{$year}-{$month}-{$serialPart}";
    }

    protected function risNumber(Issuance $issuance): string
    {
        $issuance->loadMissing(['consolidatedRequisition', 'requisition']);

        return (string) (
            $issuance->consolidatedRequisition?->reference_code
            ?? $issuance->requisition?->reference_code
            ?? $issuance->requisition?->getAttribute('ris_number')
            ?? ''
        );
    }

    protected function resolveUnitCost(Issuance $issuance): ?float
    {
        if ($issuance->unit_cost !== null) {
            return (float) $issuance->unit_cost;
        }

        if (! $issuance->item_id) {
            return null;
        }

        $unitCost = Acquisition::query()
            ->where('item_id', $issuance->item_id)
            ->orderByDesc('acquisition_date')
            ->value('unit_cost');

        return $unitCost !== null ? (float) $unitCost : null;
    }

    protected function resolveLineAmount(Issuance $issuance, ?float $unitCost): ?float
    {
        if ($issuance->amount !== null) {
            return (float) $issuance->amount;
        }

        if ($unitCost === null || $issuance->quantity === null) {
            return null;
        }

        return $unitCost * (float) $issuance->quantity;
    }
}
