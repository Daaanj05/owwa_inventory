<?php

namespace App\Http\Controllers;

use App\Filament\Pages\StockLevels;
use App\Filament\Resources\Acquisitions\AcquisitionCustodyQuery;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\Issuances\IssuanceResource;
use App\Filament\Resources\Requisitions\Actions\RequisitionExportActions;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Http\Concerns\LogsExportActivity;
use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\Disposal;
use App\Models\InspectionAcceptanceReport;
use App\Models\Issuance;
use App\Models\ItemCategory;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\Transfer;
use App\Models\User;
use App\Services\AcquisitionPaperworkPdfExportService;
use App\Services\AnnexA4PdfExportService;
use App\Services\OwwaItemReportService;
use App\Services\OwwaTemplateExportService;
use App\Services\StockCardPdfExportService;
use App\Services\StockLevelExportService;
use App\Support\CustodianOfficeScope;
use App\Support\OwwaExportDiagnostics;
use App\Support\OwwaExportFilename;
use App\Support\OwwaPdfBinaryMerger;
use App\Support\OwwaReferenceLabels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OwwaBulkExportController extends Controller
{
    use LogsExportActivity;

    private const MAX_IDS = 100;

    public function __construct(
        protected OwwaTemplateExportService $owwaExport,
        protected OwwaItemReportService $itemReport,
        protected StockLevelExportService $stockLevelExport,
        protected StockCardPdfExportService $stockCardPdfExport,
        protected AnnexA4PdfExportService $annexA4PdfExport,
    ) {}

    /**
     * @return array<int>
     */
    private function idsFromRequest(Request $request): array
    {
        $ids = $request->query('ids');

        if ($ids === null || $ids === '' || $ids === []) {
            return [];
        }

        if (is_string($ids)) {
            return array_values(array_filter(array_map('intval', explode(',', $ids))));
        }

        if (is_array($ids)) {
            return array_values(array_filter(array_map('intval', $ids)));
        }

        return [];
    }

    /**
     * @return array<int>
     */
    private function parseIdsWithLog(Request $request, string $resource): array
    {
        $ids = $this->idsFromRequest($request);

        Log::info('owwa_export_bulk: HTTP request', [
            'owwa_export' => true,
            'resource' => $resource,
            'raw_ids_query' => $request->query('ids'),
            'parsed_ids' => $ids,
            'parsed_count' => count($ids),
            'path' => $request->path(),
        ]);

        return $ids;
    }

    private function resolveBulkExportLayout(Request $request): string
    {
        $layout = (string) $request->query('export_layout', 'workbook');
        abort_unless(in_array($layout, ['workbook', 'individual'], true), 422);

        return $layout;
    }

    private function resolveBackUrl(Request $request, string $fallback): string
    {
        $backUrl = (string) $request->query('back_url', '');

        if ($backUrl === '') {
            $backUrl = (string) $request->headers->get('referer', '');
        }

        return $backUrl !== '' ? $backUrl : $fallback;
    }

    /**
     * @param  Collection<int, Acquisition|Issuance|Transfer|Disposal>  $records
     */
    private function bulkIndividualDownloadsResponse(Collection $records, string $routeName, string $heading, string $backUrl): Response
    {
        $links = [];
        foreach ($records as $record) {
            $links[] = [
                'label' => (string) ($record->getAttribute('reference_code') ?? ('#'.$record->getKey())),
                'url' => route($routeName, [
                    'ids' => $record->getKey(),
                    'export_layout' => 'workbook',
                ]),
            ];
        }

        return response()->view('owwa.bulk-export-links', [
            'heading' => $heading,
            'links' => $links,
            'backUrl' => $backUrl,
        ]);
    }

    public function annexA1(Request $request): StreamedResponse
    {
        abort_unless(StockLevels::canAccess(), 403);

        $pairs = $this->resolveStockLevelPairs($request);

        abort_if($pairs->isEmpty(), 404);

        $this->logExportActivity('Exported bulk Annex A.1 report', properties: ['count' => $pairs->count()]);

        return $this->itemReport->downloadAnnexA1Bulk($pairs);
    }

    public function stockCards(Request $request): StreamedResponse|Response
    {
        abort_unless(StockLevels::canAccess(), 403);

        OwwaExportDiagnostics::raiseMemoryLimit('512M');
        OwwaExportDiagnostics::registerOomGuard($request->path());

        try {
            $pairs = $this->resolveStockLevelPairs($request);

            $category = ItemCategory::query()->find((int) $request->query('category'));
            $slug = $category?->getTemplateSlug() ?? 'consumables';
            $format = (string) $request->query('format', 'xlsx');

            OwwaExportDiagnostics::info('stock_cards_start', [
                'pair_count' => $pairs->count(),
                'pairs' => $pairs->take(20)->values()->all(),
                'category_id' => $category?->id,
                'category_slug' => $slug,
                'format' => $format,
                'has_explicit_pairs' => filled($request->query('pairs')),
            ]);

            $this->logExportActivity('Exported bulk stock cards', properties: [
                'count' => $pairs->count(),
                'category' => $slug,
                'format' => $format,
            ]);

            $response = $format === 'pdf'
                ? $this->stockCardPdfExport->downloadMerged($pairs, $slug)
                : match ($slug) {
                    'ppe' => $this->itemReport->downloadPropertyCardBulk($pairs),
                    'semi_expendable' => $this->itemReport->downloadAnnexA1Bulk($pairs),
                    default => $this->itemReport->downloadStockCardBulk($pairs),
                };

            OwwaExportDiagnostics::info('stock_cards_built', [
                'pair_count' => $pairs->count(),
                'category_slug' => $slug,
                'format' => $format,
            ]);

            return $response;
        } catch (Throwable $throwable) {
            OwwaExportDiagnostics::error('stock_cards_failed', $throwable, [
                'category' => $request->query('category'),
                'format' => $request->query('format', 'xlsx'),
                'pairs' => $request->query('pairs'),
            ]);

            throw $throwable;
        }
    }

    /**
     * @return Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>
     */
    protected function resolveStockLevelPairs(Request $request): Collection
    {
        $user = Auth::user();
        $scopedOfficeId = $user?->office_id ? (int) $user->office_id : null;

        try {
            return $this->stockLevelExport->resolvePairsFromRequest($request, $scopedOfficeId);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first()
                ?? 'Invalid stock card export selection.';

            abort(422, is_string($message) ? $message : 'Invalid stock card export selection.');
        }
    }

    public function annexA4(Request $request): StreamedResponse|Response
    {
        abort_unless(StockLevels::canAccess(), 403);

        $categoryId = $request->query('category');
        $search = $request->query('search');
        $restockFilter = (string) $request->query('restock_filter', 'active');
        $pairs = $this->itemReport->stockLevelPairsForAnnexA1Bulk(
            $categoryId !== null && $categoryId !== '' ? (int) $categoryId : null,
            is_string($search) && $search !== '' ? $search : null,
            $restockFilter,
        );

        abort_if($pairs->isEmpty(), 404);

        $format = (string) $request->query('format', 'xlsx');

        $this->logExportActivity('Exported bulk Annex A.4 registry', properties: [
            'count' => $pairs->count(),
            'format' => $format,
        ]);

        if ($format === 'pdf') {
            return $this->annexA4PdfExport->download($pairs);
        }

        return $this->itemReport->downloadAnnexA4Bulk($pairs);
    }

    public function propertyCards(Request $request): StreamedResponse
    {
        abort_unless(StockLevels::canAccess(), 403);

        $categoryId = $request->query('category');
        $search = $request->query('search');
        $restockFilter = (string) $request->query('restock_filter', 'active');
        $pairs = $this->itemReport->stockLevelPairsForPropertyCardBulk(
            $categoryId !== null && $categoryId !== '' ? (int) $categoryId : null,
            is_string($search) && $search !== '' ? $search : null,
            $restockFilter,
        );

        abort_if($pairs->isEmpty(), 404);

        $this->logExportActivity('Exported bulk property cards report', properties: ['count' => $pairs->count()]);

        return $this->itemReport->downloadPropertyCardBulk($pairs);
    }

    public function acquisitions(Request $request): BinaryFileResponse|StreamedResponse|Response
    {
        abort_unless(AcquisitionResource::canViewAny(), 403);

        $ids = $this->parseIdsWithLog($request, 'acquisitions');
        abort_if($ids === [], 404);
        abort_if(count($ids) > self::MAX_IDS, 422);

        $ids = array_values(array_unique($ids));

        $this->logExportActivity('Exported bulk acquisitions report', properties: ['ids' => $ids]);

        $records = AcquisitionCustodyQuery::forBulkExport($ids);

        abort_unless($records->count() === count($ids), 404);

        $layout = $this->resolveBulkExportLayout($request);

        if ($layout === 'individual' && $records->count() > 1) {
            return $this->bulkIndividualDownloadsResponse(
                $records,
                'owwa.export.bulk.acquisitions',
                'Download acquisitions',
                $this->resolveBackUrl($request, AcquisitionResource::getUrl()),
            );
        }

        if ($records->count() === 1) {
            return $this->owwaExport->downloadAcquisition($records->first());
        }

        return $this->mergedOwwaWorkbookResponse(
            $records,
            fn (Acquisition $record): Spreadsheet => $this->owwaExport->acquisitionFilledSpreadsheet($record),
            'acquisitions',
            $this->resolveBulkAcquisitionFormCode($records),
        );
    }

    public function issuancesTodayRsmi(Request $request): StreamedResponse|Response
    {
        $request->query->set('date_from', today()->toDateString());
        $request->query->set('date_to', today()->toDateString());

        return $this->issuancesRsmi($request);
    }

    public function issuancesRsmi(Request $request): StreamedResponse|Response
    {
        abort_unless(IssuanceResource::canViewAny(), 403);

        $format = (string) $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 422);

        $dateFrom = (string) $request->query('date_from', '');
        $dateTo = (string) $request->query('date_to', '');
        abort_unless(filled($dateFrom) && filled($dateTo), 422);
        abort_unless($dateFrom <= $dateTo, 422);

        $categoryId = (int) $request->query('category', 0);

        $query = IssuanceResource::getEloquentQuery()
            ->with(['requisition', 'item.category', 'office', 'department', 'batch'])
            ->whereDate('issuance_date', '>=', $dateFrom)
            ->whereDate('issuance_date', '<=', $dateTo)
            ->orderBy('issuance_date')
            ->orderBy('id');

        if ($categoryId > 0) {
            $query->whereHas('item', fn (Builder $builder): Builder => $builder->where('item_category_id', $categoryId));
        }

        $records = $query->limit(self::MAX_IDS + 1)->get();

        abort_if($records->isEmpty(), 404, 'No issuances found for the selected date range.');
        abort_if($records->count() > self::MAX_IDS, 422, 'Too many records (max '.self::MAX_IDS.'). Narrow the date range.');

        $this->logExportActivity('Exported RSMI report', properties: [
            'count' => $records->count(),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'format' => $format,
            'category' => $categoryId > 0 ? $categoryId : null,
        ]);

        abort_unless($this->owwaExport->canExportIssuancesAsRsmiWorkbook($records), 422, 'Issuances cannot be exported as RSMI workbook.');

        if ($format === 'pdf') {
            $spreadsheet = $this->owwaExport->issuancesRsmiFilledSpreadsheet($records);
            $binary = $this->owwaExport->spreadsheetToPdfBinary($spreadsheet);
            $spreadsheet->disconnectWorksheets();

            return response($binary, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.OwwaExportFilename::batch('RSMI', ext: 'pdf').'"',
            ]);
        }

        if ($records->count() === 1) {
            return $this->owwaExport->downloadIssuance($records->first());
        }

        return $this->owwaExport->downloadIssuancesRsmiCombined($records);
    }

    public function issuances(Request $request): BinaryFileResponse|StreamedResponse|Response
    {
        abort_unless(IssuanceResource::canViewAny(), 403);

        $ids = $this->parseIdsWithLog($request, 'issuances');
        abort_if($ids === [], 404);
        abort_if(count($ids) > self::MAX_IDS, 422);

        $ids = array_values(array_unique($ids));

        $this->logExportActivity('Exported bulk issuances report', properties: ['ids' => $ids]);

        $records = IssuanceResource::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereKey($ids)
            ->get();

        abort_unless($records->count() === count($ids), 404);

        $layout = $this->resolveBulkExportLayout($request);

        if ($layout === 'individual' && $records->count() > 1) {
            return $this->bulkIndividualDownloadsResponse(
                $records,
                'owwa.export.bulk.issuances',
                'Download issuances',
                $this->resolveBackUrl($request, IssuanceResource::getUrl()),
            );
        }

        if ($records->count() === 1) {
            return $this->owwaExport->downloadIssuance($records->first());
        }

        if ($this->owwaExport->canExportIssuancesAsRsmiWorkbook($records)) {
            return $this->owwaExport->downloadIssuancesRsmiWorkbook($records);
        }

        return $this->mergedOwwaWorkbookResponse(
            $records,
            fn (Issuance $record): Spreadsheet => $this->owwaExport->issuanceFilledSpreadsheet($record),
            'issuances',
            $this->resolveBulkIssuanceFormCode($records),
        );
    }

    public function transfers(Request $request): BinaryFileResponse|StreamedResponse|Response
    {
        abort_unless(TransferResource::canViewAny(), 403);

        $ids = $this->parseIdsWithLog($request, 'transfers');
        abort_if($ids === [], 404);
        abort_if(count($ids) > self::MAX_IDS, 422);

        $ids = array_values(array_unique($ids));

        $this->logExportActivity('Exported bulk transfers report', properties: ['ids' => $ids]);

        $records = TransferResource::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereKey($ids)
            ->get();

        abort_unless($records->count() === count($ids), 404);

        $layout = $this->resolveBulkExportLayout($request);

        if ($layout === 'individual' && $records->count() > 1) {
            return $this->bulkIndividualDownloadsResponse(
                $records,
                'owwa.export.bulk.transfers',
                'Download transfers',
                $this->resolveBackUrl($request, TransferResource::getUrl()),
            );
        }

        if ($records->count() === 1) {
            return $this->owwaExport->downloadTransfer($records->first());
        }

        return $this->mergedOwwaWorkbookResponse(
            $records,
            fn (Transfer $record): Spreadsheet => $this->owwaExport->transferFilledSpreadsheet($record),
            'transfers',
        );
    }

    public function disposals(Request $request): BinaryFileResponse|StreamedResponse|Response
    {
        abort_unless(DisposalResource::canViewAny(), 403);

        $ids = $this->parseIdsWithLog($request, 'disposals');
        abort_if($ids === [], 404);
        abort_if(count($ids) > self::MAX_IDS, 422);

        $ids = array_values(array_unique($ids));

        $this->logExportActivity('Exported bulk disposals report', properties: ['ids' => $ids]);

        $records = DisposalResource::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereKey($ids)
            ->get();

        abort_unless($records->count() === count($ids), 404);

        $layout = $this->resolveBulkExportLayout($request);

        if ($layout === 'individual' && $records->count() > 1) {
            return $this->bulkIndividualDownloadsResponse(
                $records,
                'owwa.export.bulk.disposals',
                'Download disposals',
                $this->resolveBackUrl($request, DisposalResource::getUrl()),
            );
        }

        if ($records->count() === 1) {
            return $this->owwaExport->downloadDisposal($records->first());
        }

        return $this->mergedOwwaWorkbookResponse(
            $records,
            fn (Disposal $record): Spreadsheet => $this->owwaExport->disposalFilledSpreadsheet($record),
            'disposals',
        );
    }

    public function disposalsReport(Request $request): StreamedResponse|Response
    {
        abort_unless(DisposalResource::canViewAny(), 403);

        $format = (string) $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 422);

        $dateFrom = (string) $request->query('date_from', '');
        $dateTo = (string) $request->query('date_to', '');
        abort_unless(filled($dateFrom) && filled($dateTo), 422);
        abort_unless($dateFrom <= $dateTo, 422);

        $categoryId = (int) $request->query('category', 0);

        $query = DisposalResource::getEloquentQuery()
            ->with(['item.category', 'office', 'batch'])
            ->whereDate('disposal_date', '>=', $dateFrom)
            ->whereDate('disposal_date', '<=', $dateTo)
            ->whereHas('batch', fn (Builder $builder): Builder => $builder->whereNotNull('confirmed_at'))
            ->orderBy('disposal_date')
            ->orderBy('id');

        if ($categoryId > 0) {
            $query->whereHas('item', fn (Builder $builder): Builder => $builder->where('item_category_id', $categoryId));
        }

        $records = $query->limit(self::MAX_IDS + 1)->get();

        abort_if($records->isEmpty(), 404, 'No confirmed disposals found for the selected date range.');
        abort_if($records->count() > self::MAX_IDS, 422, 'Too many records (max '.self::MAX_IDS.'). Narrow the date range.');

        $this->logExportActivity('Exported disposal date-range report', properties: [
            'count' => $records->count(),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'format' => $format,
            'category' => $categoryId > 0 ? $categoryId : null,
        ]);

        if ($format === 'pdf') {
            if ($records->count() === 1) {
                $spreadsheet = $this->owwaExport->disposalFilledSpreadsheet($records->first());
                $binary = $this->owwaExport->spreadsheetToPdfBinary($spreadsheet);
                $spreadsheet->disconnectWorksheets();

                return response($binary, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.OwwaExportFilename::batch('Disposal', ext: 'pdf').'"',
                ]);
            }

            $merged = $this->buildMergedOwwaWorkbookAllSheets(
                $records,
                fn (Disposal $record): Spreadsheet => $this->owwaExport->disposalFilledSpreadsheet($record),
                'disposals',
                fn (Disposal $record): string => (string) ($record->reference_code ?? ('Disposal_'.$record->getKey())),
            );
            $binary = $this->owwaExport->spreadsheetToPdfBinary($merged, max(90, 45 * $merged->getSheetCount()));
            $merged->disconnectWorksheets();

            return response($binary, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.OwwaExportFilename::batch('Disposal', ext: 'pdf').'"',
            ]);
        }

        if ($records->count() === 1) {
            return $this->owwaExport->downloadDisposal($records->first());
        }

        return $this->mergedOwwaWorkbookResponse(
            $records,
            fn (Disposal $record): Spreadsheet => $this->owwaExport->disposalFilledSpreadsheet($record),
            'disposals',
        );
    }

    public function requisitions(Request $request): BinaryFileResponse|StreamedResponse|Response
    {
        $user = Auth::user();
        abort_unless(
            $user instanceof User && RequisitionExportActions::userCanExportRis($user),
            403,
        );

        $ids = $this->parseIdsWithLog($request, 'requisitions');
        abort_if($ids === [], 404);
        abort_if(count($ids) > self::MAX_IDS, 422);

        $ids = array_values(array_unique($ids));

        $records = RequisitionResource::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereKey($ids)
            ->get();

        abort_unless($records->count() === count($ids), 404);

        $layout = $this->resolveBulkExportLayout($request);
        $format = (string) $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 422);

        $this->logExportActivity(
            $format === 'pdf'
                ? 'Exported RIS PDF'.($records->count() === 1 ? ' '.$records->first()->reference_code : ' bulk')
                : 'Exported RIS'.($records->count() === 1 ? ' '.$records->first()->reference_code : ' bulk'),
            properties: ['ids' => $ids, 'format' => $format],
        );

        if (($layout === 'individual' || $format === 'pdf') && $records->count() > 1) {
            $links = [];
            foreach ($records as $record) {
                $links[] = [
                    'label' => (string) ($record->reference_code ?? ('#'.$record->getKey())),
                    'url' => route('owwa.export.bulk.requisitions', array_filter([
                        'ids' => $record->getKey(),
                        'export_layout' => 'workbook',
                        'format' => $format === 'pdf' ? 'pdf' : null,
                    ])),
                ];
            }

            return response()->view('owwa.bulk-export-links', [
                'heading' => $format === 'pdf' ? 'Download RIS PDFs' : 'Download requisitions',
                'links' => $links,
                'backUrl' => $this->resolveBackUrl($request, RequisitionResource::getUrl()),
            ]);
        }

        if ($records->count() === 1) {
            return $format === 'pdf'
                ? $this->owwaExport->downloadRequisitionPdf($records->first())
                : $this->owwaExport->downloadRequisition($records->first());
        }

        return $this->mergedOwwaWorkbookResponse(
            $records,
            fn (Requisition $record): Spreadsheet => $this->owwaExport->requisitionFilledSpreadsheet($record),
            'requisitions',
            'RIS',
        );
    }

    public function procurement(Request $request, AcquisitionPaperworkPdfExportService $pdfExport): StreamedResponse|Response
    {
        $documentType = (string) $request->query('document_type', '');
        abort_unless(in_array($documentType, ['pr', 'po', 'iar'], true), 422);

        $format = (string) $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 422);

        $dateFrom = (string) $request->query('date_from', '');
        $dateTo = (string) $request->query('date_to', '');
        abort_unless(filled($dateFrom) && filled($dateTo), 422);
        abort_unless($dateFrom <= $dateTo, 422);

        $categoryId = (int) $request->query('category', 0);
        $formCode = match ($documentType) {
            'po' => 'PO',
            'iar' => 'IAR',
            default => 'PR',
        };

        $records = match ($documentType) {
            'po' => $this->procurementPurchaseOrders($dateFrom, $dateTo, $categoryId),
            'iar' => $this->procurementInspectionReports($dateFrom, $dateTo, $categoryId),
            default => $this->procurementPurchaseRequests($dateFrom, $dateTo, $categoryId),
        };

        abort_if($records->isEmpty(), 404, 'No '.$formCode.' records found for the selected date range.');
        abort_if($records->count() > self::MAX_IDS, 422, 'Too many records (max '.self::MAX_IDS.'). Narrow the date range.');

        $this->logExportActivity('bulk_procurement_'.$documentType.($format === 'pdf' ? '_pdf' : ''), null, [
            'count' => $records->count(),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'format' => $format,
            'category' => $categoryId > 0 ? $categoryId : null,
        ]);

        if ($format === 'pdf') {
            return $this->procurementMergedPdfResponse($records, $documentType, $formCode, $pdfExport);
        }

        return $this->mergedOwwaWorkbookResponseAllSheets(
            $records,
            $this->procurementSpreadsheetBuilder($documentType, $pdfExport),
            $documentType,
            $formCode,
            $this->procurementSheetTitleResolver($documentType),
        );
    }

    /**
     * Bulk PDF must match single-record “Save as PDF” fidelity (LibreOffice).
     * Prefer per-record LibreOffice PDFs merged into one file; fall back to one
     * LibreOffice convert of the merged workbook; Dompdf only if LO is unavailable.
     *
     * @param  Collection<int, AcquisitionPaperwork|PurchaseOrder|InspectionAcceptanceReport>  $records
     */
    protected function procurementMergedPdfResponse(
        Collection $records,
        string $documentType,
        string $formCode,
        AcquisitionPaperworkPdfExportService $pdfExport,
    ): Response {
        $builder = $this->procurementSpreadsheetBuilder($documentType, $pdfExport);
        $perRecordTimeout = max(60, (int) config('services.libreoffice.timeout', 90));
        $pdfParts = [];

        foreach ($records as $record) {
            $spreadsheet = $builder($record);
            $part = $this->owwaExport->spreadsheetToLibreOfficePdfBinary($spreadsheet, $perRecordTimeout);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            if ($part === null) {
                $pdfParts = [];
                break;
            }

            $pdfParts[] = $part;
        }

        $binary = null;

        if (count($pdfParts) === $records->count()) {
            try {
                $binary = OwwaPdfBinaryMerger::merge($pdfParts);
            } catch (Throwable $exception) {
                Log::warning('owwa_export_bulk: PDF merge failed; trying merged-workbook LibreOffice.', [
                    'owwa_export' => true,
                    'document_type' => $documentType,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($binary === null) {
            $merged = $this->buildMergedOwwaWorkbookAllSheets(
                $records,
                $builder,
                $documentType,
                $this->procurementSheetTitleResolver($documentType),
            );

            $mergedTimeout = max(
                $perRecordTimeout,
                45 * max(1, $merged->getSheetCount()),
                120,
            );
            $binary = $this->owwaExport->spreadsheetToLibreOfficePdfBinary($merged, $mergedTimeout);

            if ($binary === null) {
                $binary = $this->owwaExport->spreadsheetToDompdfBinary($merged);
            }

            $merged->disconnectWorksheets();
            unset($merged);
        }

        $filename = OwwaExportFilename::batch($formCode, ext: 'pdf');

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return callable(AcquisitionPaperwork|PurchaseOrder|InspectionAcceptanceReport): Spreadsheet
     */
    protected function procurementSpreadsheetBuilder(string $documentType, AcquisitionPaperworkPdfExportService $pdfExport): callable
    {
        return function ($record) use ($documentType, $pdfExport): Spreadsheet {
            return match ($documentType) {
                'po' => $pdfExport->purchaseOrderFilledSpreadsheet($record),
                'iar' => $this->owwaExport->acquisitionPaperworkFilledSpreadsheet(
                    $pdfExport->paperworkFromIar($record),
                    'iar',
                ),
                default => $this->owwaExport->acquisitionPaperworkFilledSpreadsheet($record, 'pr'),
            };
        };
    }

    /**
     * @return callable(AcquisitionPaperwork|PurchaseOrder|InspectionAcceptanceReport): string
     */
    protected function procurementSheetTitleResolver(string $documentType): callable
    {
        return function ($record) use ($documentType): string {
            return match ($documentType) {
                'po' => (string) ($record->number ?? ('PO_'.$record->getKey())),
                'iar' => (string) ($record->number ?? ('IAR_'.$record->getKey())),
                default => (string) ($record->pr_number ?? $record->reference_code ?? ('PR_'.$record->getKey())),
            };
        };
    }

    /**
     * @return Collection<int, AcquisitionPaperwork>
     */
    protected function procurementPurchaseRequests(string $dateFrom, string $dateTo, int $categoryId): Collection
    {
        $query = AcquisitionResource::getEloquentQuery()
            ->whereNull('archived_at')
            ->whereDate('pr_date', '>=', $dateFrom)
            ->whereDate('pr_date', '<=', $dateTo)
            ->orderBy('pr_date')
            ->orderBy('id');

        if ($categoryId > 0) {
            $query->where('item_category_id', $categoryId);
        }

        return $query->limit(self::MAX_IDS + 1)->get();
    }

    /**
     * @return Collection<int, PurchaseOrder>
     */
    protected function procurementPurchaseOrders(string $dateFrom, string $dateTo, int $categoryId): Collection
    {
        return PurchaseOrder::query()
            ->with(['purchaseRequest.itemCategory', 'orderedLines.item.category', 'orderedLines.item.uacsObjectCode'])
            ->whereNull('archived_at')
            ->whereDate('po_date', '>=', $dateFrom)
            ->whereDate('po_date', '<=', $dateTo)
            ->whereHas('purchaseRequest', function (Builder $builder) use ($categoryId): void {
                CustodianOfficeScope::applyOfficeColumn($builder);
                if ($categoryId > 0) {
                    $builder->where('item_category_id', $categoryId);
                }
            })
            ->orderBy('po_date')
            ->orderBy('id')
            ->limit(self::MAX_IDS + 1)
            ->get();
    }

    /**
     * @return Collection<int, InspectionAcceptanceReport>
     */
    protected function procurementInspectionReports(string $dateFrom, string $dateTo, int $categoryId): Collection
    {
        return InspectionAcceptanceReport::query()
            ->with([
                'purchaseOrder.purchaseRequest.office',
                'purchaseOrder.purchaseRequest.itemCategory',
                'lines.item.category',
                'lines.item.uacsObjectCode',
            ])
            ->whereNull('archived_at')
            ->whereDate('iar_date', '>=', $dateFrom)
            ->whereDate('iar_date', '<=', $dateTo)
            ->whereHas('purchaseOrder.purchaseRequest', function (Builder $builder) use ($categoryId): void {
                CustodianOfficeScope::applyOfficeColumn($builder);
                if ($categoryId > 0) {
                    $builder->where('item_category_id', $categoryId);
                }
            })
            ->orderBy('iar_date')
            ->orderBy('id')
            ->limit(self::MAX_IDS + 1)
            ->get();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $records
     * @param  callable(TModel): Spreadsheet  $toSpreadsheet
     * @param  callable(TModel): string  $sheetBaseTitle
     */
    protected function buildMergedOwwaWorkbookAllSheets(
        Collection $records,
        callable $toSpreadsheet,
        string $label,
        callable $sheetBaseTitle,
    ): Spreadsheet {
        $merged = new Spreadsheet;
        $removedDefaultSheet = false;
        $usedSheetTitles = [];

        foreach ($records as $record) {
            $source = $toSpreadsheet($record);
            $baseTitle = Str::slug((string) $sheetBaseTitle($record), '_');
            if ($baseTitle === '') {
                $baseTitle = $label.'_'.$record->getKey();
            }

            $sheetIndex = 0;

            while ($source->getSheetCount() > 0) {
                $sheet = $source->getSheet(0);
                $originalTitle = $sheet->getTitle();
                $isTechSpecSheet = str_contains($originalTitle, 'Technical Specification')
                    || str_contains((string) $sheet->getCell('A1')->getValue(), 'TECHNICAL SPECIFICATION');

                $title = match (true) {
                    $sheetIndex === 0 => $baseTitle,
                    $isTechSpecSheet => $baseTitle.'_TechSpec',
                    default => $baseTitle.'_'.($sheetIndex + 1),
                };
                $sheet->setTitle($this->uniqueExcelSheetTitle($title, $usedSheetTitles));
                $sheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VISIBLE);
                $merged->addExternalSheet($sheet);

                if (! $removedDefaultSheet) {
                    $merged->removeSheetByIndex(0);
                    $removedDefaultSheet = true;
                }

                $sheetIndex++;
            }

            unset($source);
        }

        $merged->setActiveSheetIndex(0);

        return $merged;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $records
     * @param  callable(TModel): Spreadsheet  $toSpreadsheet
     * @param  callable(TModel): string  $sheetBaseTitle
     */
    protected function mergedOwwaWorkbookResponseAllSheets(
        Collection $records,
        callable $toSpreadsheet,
        string $label,
        ?string $formCode,
        callable $sheetBaseTitle,
    ): StreamedResponse {
        $merged = $this->buildMergedOwwaWorkbookAllSheets($records, $toSpreadsheet, $label, $sheetBaseTitle);

        $writer = new Xlsx($merged);
        $downloadName = $formCode !== null
            ? OwwaExportFilename::batch($formCode)
            : OwwaExportFilename::bulkWorkbook($label);

        return response()->streamDownload(function () use ($writer, $merged): void {
            $writer->save('php://output');
            $merged->disconnectWorksheets();
        }, $downloadName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $records
     * @param  callable(TModel): Spreadsheet  $toSpreadsheet
     */
    protected function mergedOwwaWorkbookResponse(Collection $records, callable $toSpreadsheet, string $label, ?string $formCode = null): StreamedResponse
    {
        $merged = new Spreadsheet;
        $removedDefaultSheet = false;
        $usedSheetTitles = [];

        foreach ($records as $record) {
            $source = $toSpreadsheet($record);
            $sheet = $source->getSheet(0);

            $ref = $record->getAttribute('reference_code') ?? ('id_'.$record->getKey());
            $sheetTitle = $label === 'requisitions'
                ? (string) $ref
                : Str::slug((string) $ref, '_');
            if ($sheetTitle === '') {
                $sheetTitle = (string) $label.'_'.$record->getKey();
            }

            $sheet->setTitle($this->uniqueExcelSheetTitle($sheetTitle, $usedSheetTitles));

            $merged->addExternalSheet($sheet);

            if (! $removedDefaultSheet) {
                $merged->removeSheetByIndex(0);
                $removedDefaultSheet = true;
            }

            $source->disconnectWorksheets();
            unset($source);
        }

        $merged->setActiveSheetIndex(0);

        $writer = new Xlsx($merged);
        $downloadName = $formCode !== null
            ? OwwaExportFilename::batch($formCode)
            : OwwaExportFilename::bulkWorkbook($label);

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $downloadName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  Collection<int, Issuance>  $records
     */
    protected function resolveBulkIssuanceFormCode(Collection $records): string
    {
        $slug = OwwaReferenceLabels::itemCategorySlug($records->first()?->item_id);

        return match ($slug) {
            'ppe' => 'PAR',
            'semi_expendable' => 'ICS',
            default => 'Issuances',
        };
    }

    /**
     * @param  Collection<int, Acquisition>  $records
     */
    protected function resolveBulkAcquisitionFormCode(Collection $records): string
    {
        $slug = OwwaReferenceLabels::itemCategorySlug($records->first()?->item_id);

        return match ($slug) {
            'ppe' => 'PC',
            'semi_expendable' => 'AnnexA1',
            default => 'SC',
        };
    }

    /**
     * @param  array<string, true>  $usedTitles
     */
    private function uniqueExcelSheetTitle(string $base, array &$usedTitles): string
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
}
