<?php

namespace App\Services;

use App\Models\AiProcurementItem;
use App\Models\AiProcurementRun;
use App\Support\AiProcurementRunViewPresenter;
use App\Support\OwwaExportFilename;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AiProcurementRunPdfExportService
{
    public function downloadRun(AiProcurementRun $run): Response
    {
        $run->loadMissing(['creator', 'items']);

        $items = $run->items
            ->sortBy(fn (AiProcurementItem $item): string => sprintf('%02d-%s', $this->priorityRank($item->priority), (string) $item->item_name))
            ->values()
            ->map(fn (AiProcurementItem $item): array => [
                'priority' => ucfirst((string) ($item->priority ?? '')),
                'section' => Str::headline((string) ($item->section ?? '')),
                'item_name' => (string) ($item->item_name ?? ''),
                'office_name' => (string) ($item->office_name ?? ''),
                'property_number' => (string) ($item->property_number ?? ''),
                'current_stock' => $item->current_stock,
                'avg_monthly_usage' => $item->avg_monthly_usage,
                'months_cover' => $item->months_cover,
                'suggested_qty' => AiProcurementRunViewPresenter::formatSuggestedQty($item->suggested_qty_min, $item->suggested_qty_max),
                'reason' => (string) ($item->reason ?? ''),
                'action_status' => Str::headline((string) ($item->action_status ?? '')),
            ])
            ->all();

        $pdf = Pdf::loadView('reports.ai-procurement-run-pdf', [
            'run' => $run,
            'reference' => 'Run #'.$run->id,
            'statusLabel' => $this->statusLabel((string) $run->status),
            'summary' => $this->plainSummary($run->summary, null),
            'items' => $items,
            'generatedAt' => now(),
        ]);

        return $this->render($pdf)->download(OwwaExportFilename::pdfExport('AI-Run-'.$run->id));
    }

    public function downloadHistory(?string $dateFrom, ?string $dateTo, ?string $status, bool $archived): Response
    {
        $runs = AiProcurementRun::query()
            ->with('creator')
            ->withCount('items')
            ->when($archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->when(filled($dateFrom), fn ($query) => $query->where('ran_at', '>=', Carbon::parse((string) $dateFrom)->startOfDay()))
            ->when(filled($dateTo), fn ($query) => $query->where('ran_at', '<=', Carbon::parse((string) $dateTo)->endOfDay()))
            ->when(filled($status), fn ($query) => $query->where('status', $status))
            ->orderByDesc('ran_at')
            ->get();

        $rows = $runs->map(fn (AiProcurementRun $run): array => [
            'reference' => 'Run #'.$run->id,
            'ran_at' => $run->ran_at?->format('M j, Y g:i A') ?? '',
            'summary' => $this->plainSummary($run->summary, 160),
            'items_count' => (int) $run->items_count,
            'status' => $this->statusLabel((string) $run->status),
            'generated_by' => $run->creator?->name ?? 'System',
        ])->all();

        $pdf = Pdf::loadView('reports.ai-procurement-history-pdf', [
            'rows' => $rows,
            'filterSummary' => $this->historyFilterSummary($dateFrom, $dateTo, $status, $archived),
            'generatedAt' => now(),
        ]);

        return $this->render($pdf)->download(OwwaExportFilename::pdfExport('AI-Recommendation-History'));
    }

    protected function render(\Barryvdh\DomPDF\PDF $pdf): \Barryvdh\DomPDF\PDF
    {
        return $pdf
            ->setPaper('a4', 'landscape')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isFontSubsettingEnabled', true);
    }

    protected function plainSummary(?string $summary, ?int $limit): string
    {
        if (blank($summary)) {
            return '';
        }

        $clean = preg_replace('/[#*_`~>]+/', '', (string) $summary) ?? (string) $summary;
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);

        return $limit !== null ? Str::limit($clean, $limit) : $clean;
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'processing' => 'Processing',
            'failed' => 'Failed',
            'pending' => 'Pending',
            'approved' => 'Approved',
            default => ucfirst($status),
        };
    }

    protected function priorityRank(?string $priority): int
    {
        return match (strtolower((string) $priority)) {
            'critical' => 0,
            'high' => 1,
            'medium' => 2,
            'low' => 3,
            default => 9,
        };
    }

    protected function historyFilterSummary(?string $dateFrom, ?string $dateTo, ?string $status, bool $archived): string
    {
        $from = filled($dateFrom) ? Carbon::parse((string) $dateFrom)->format('M j, Y') : 'Any';
        $to = filled($dateTo) ? Carbon::parse((string) $dateTo)->format('M j, Y') : 'Any';

        return implode(' · ', [
            'Period: '.$from.' to '.$to,
            'Status: '.(filled($status) ? $this->statusLabel((string) $status) : 'All'),
            $archived ? 'Archived runs' : 'Active runs',
        ]);
    }
}
