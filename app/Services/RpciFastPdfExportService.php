<?php

namespace App\Services;

use App\Models\PhysicalCountSession;
use App\Support\OwwaExportFilename;
use App\Support\PhysicalCountPageLayout;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class RpciFastPdfExportService
{
    public function __construct(
        protected OwwaItemReportService $itemReport,
    ) {}

    public function download(PhysicalCountSession $session): Response
    {
        $pages = $this->buildPages($session);

        $filename = OwwaExportFilename::transaction(
            'RPCI-fast',
            (string) $session->reference_code,
            'pdf',
        );

        return $this->makePdf($pages)->download($filename);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildPages(PhysicalCountSession $session): array
    {
        abort_unless(
            $session->count_type === PhysicalCountSession::TYPE_RPCI,
            422,
            'Fast RPCI PDF is only available for consumable physical count sessions.',
        );

        return [$this->itemReport->buildRpciFastPage($session)];
    }

    public function minDetailRows(): int
    {
        return PhysicalCountPageLayout::templateDetailRows('RPCI');
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function renderHtml(array $pages, bool $forDomPdf = false, bool $showGeneratedOn = false): string
    {
        return view('reports.rpci-fast', [
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
        return Pdf::loadView('reports.rpci-fast', [
            'pages' => $pages,
            'minRows' => $this->minDetailRows(),
            'forDomPdf' => true,
            'showGeneratedOn' => true,
            'generatedOn' => now()->timezone(config('app.timezone'))->format('Y-m-d H:i'),
        ])
            ->setPaper('a4', 'landscape')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isFontSubsettingEnabled', true);
    }
}
