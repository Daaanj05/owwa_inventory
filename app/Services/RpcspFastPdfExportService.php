<?php

namespace App\Services;

use App\Models\PhysicalCountSession;
use App\Support\OwwaExportFilename;
use App\Support\PhysicalCountPageLayout;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class RpcspFastPdfExportService
{
    public function __construct(
        protected OwwaItemReportService $itemReport,
    ) {}

    public function download(PhysicalCountSession $session): Response
    {
        $pages = $this->buildPages($session);

        $filename = OwwaExportFilename::transaction(
            'RPCSP-fast',
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
            $session->count_type === PhysicalCountSession::TYPE_RPCSP,
            422,
            'Fast RPCSP PDF is only available for semi-expendable physical count sessions.',
        );

        return $this->itemReport->buildRpcspFastPages($session);
    }

    public function minDetailRows(): int
    {
        return PhysicalCountPageLayout::templateDetailRows('RPCSP');
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function renderHtml(array $pages, bool $forDomPdf = false, bool $showGeneratedOn = false): string
    {
        return view('reports.rpcsp-fast', [
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
        return Pdf::loadView('reports.rpcsp-fast', [
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
