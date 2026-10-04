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
use App\Services\AnnexA4PdfExportService;
use App\Services\InspectionAcceptanceReportFastExcelExportService;
use App\Services\InspectionAcceptanceReportFastPdfExportService;
use App\Services\OwwaItemReportService;
use App\Services\OwwaTemplateExportService;
use App\Services\PurchaseOrderFastExcelExportService;
use App\Services\PurchaseOrderFastPdfExportService;
use App\Services\PurchaseRequestFastExcelExportService;
use App\Services\PurchaseRequestFastPdfExportService;
use App\Services\RsmiFastPdfExportService;
use App\Services\StockCardFastExcelExportService;
use App\Services\StockCardFastPdfExportService;
use App\Services\StockCardFastQueuedExportService;
use App\Services\StockCardPdfExportService;
use App\Services\StockLevelExportService;
use App\Support\CustodianOfficeScope;
use App\Support\OwwaExportDiagnostics;
use App\Support\OwwaExportFilename;
use App\Support\OwwaReferenceLabels;
use App\Support\StockCardLedgerDateRange;
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
        protected StockCardFastPdfExportService $stockCardFastPdfExport,
        protected StockCardFastExcelExportService $stockCardFastExcelExport,
        protected StockCardFastQueuedExportService $stockCardFastQueuedExport,
        protected AnnexA4PdfExportService $annexA4PdfExport,
        protected RsmiFastPdfExportService $rsmiFastPdfExport,
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

            $dateRange = StockCardLedgerDateRange::fromRequest($request);
            $dateFrom = $dateRange['from'] ?? null;
            $dateTo = $dateRange['to'] ?? null;

            $response = $format === 'pdf'
                ? $this->stockCardPdfExport->downloadMerged($pairs, $slug, $dateFrom, $dateTo)
                : match ($slug) {
                    'ppe' => $this->itemReport->downloadPropertyCardBulk($pairs),
                    'semi_expendable' => $this->itemReport->downloadAnnexA1Bulk($pairs),
                    default => $this->itemReport->downloadStockCardBulk($pairs, $dateFrom, $dateTo),
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

    public function stockCardsFast(Request $request): Response|BinaryFileResponse
    {
        abort_unless(StockLevels::canAccess(), 403);

        $previewHtml = $request->boolean('preview');
        $request->query->set(
            'export_max',
            (string) ($previewHtml ? StockLevelExportService::BATCH_SIZE : StockLevelExportService::FAST_MAX),
        );
        $request->query->set('fast_pack', $previewHtml ? '0' : '1');

        $pairs = $this->resolveStockLevelPairs($request);

        abort_if($pairs->isEmpty(), 404);

        $dateRange = StockCardLedgerDateRange::fromRequest($request);
        $dateFrom = $dateRange['from'] ?? null;
        $dateTo = $dateRange['to'] ?? null;

        $count = $pairs->count();
        $packZip = ! $previewHtml && $count > StockLevelExportService::BATCH_SIZE;

        $this->logExportActivity('Exported bulk stock cards (PDF)', properties: [
            'count' => $count,
            'format' => $packZip ? 'zip' : 'pdf',
            'mode' => 'fast',
            'pack' => $packZip ? 'zip' : 'single',
            'date_from' => $dateFrom?->toDateString(),
            'date_to' => $dateTo?->toDateString(),
        ]);

        if ($packZip) {
            return $this->stockCardFastQueuedExport->downloadZip($pairs, 'pdf', $dateFrom, $dateTo);
        }

        return $this->stockCardFastPdfExport->download(
            $pairs,
            previewHtml: $previewHtml,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );
    }

    public function stockCardsFastExcel(Request $request): StreamedResponse|BinaryFileResponse
    {
        abort_unless(StockLevels::canAccess(), 403);

        $request->query->set('export_max', (string) StockLevelExportService::FAST_MAX);
        $request->query->set('fast_pack', '1');

        $pairs = $this->resolveStockLevelPairs($request);

        abort_if($pairs->isEmpty(), 404);

        $dateRange = StockCardLedgerDateRange::fromRequest($request);
        $dateFrom = $dateRange['from'] ?? null;
        $dateTo = $dateRange['to'] ?? null;

        $count = $pairs->count();
        $packZip = $count > StockLevelExportService::BATCH_SIZE;

        $this->logExportActivity('Exported bulk stock cards (Excel)', properties: [
            'count' => $count,
            'format' => $packZip ? 'zip' : 'xlsx',
            'mode' => 'fast',
            'pack' => $packZip ? 'zip' : 'single',
            'date_from' => $dateFrom?->toDateString(),
            'date_to' => $dateTo?->toDateString(),
        ]);

        if ($packZip) {
            return $this->stockCardFastQueuedExport->downloadZip($pairs, 'xlsx', $dateFrom, $dateTo);
        }

        return $this->stockCardFastExcelExport->download($pairs, $dateFrom, $dateTo);
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

    public function issuancesRsmi(Request $request): StreamedResponse|Response|BinaryFileResponse
    {
        abort_unless(IssuanceResource::canViewAny(), 403);

        $format = (string) $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 422);

        $dateFrom = (string) $request->query('date_from', '');
        $dateTo = (string) $request->query('date_to', '');
        abort_unless(filled($dateFrom) && filled($dateTo), 422);
        abort_unless($dateFrom <= $dateTo, 422);

        $categoryId = (int) $request->query('category', 0);
        $maxRecords = $format === 'pdf'
            ? StockLevelExportService::FAST_MAX
            : self::MAX_IDS;

        $query = IssuanceResource::getEloquentQuery()
            ->with([
                'requisition',
                'consolidatedRequisition',
                'item.category',
                'office',
                'department',
                'batch',
                'issuedBy',
            ])
            ->whereDate('issuance_date', '>=', $dateFrom)
            ->whereDate('issuance_date', '<=', $dateTo)
            ->orderBy('issuance_date')
            ->orderBy('id');

        if ($categoryId > 0) {
            $query->whereHas('item', fn (Builder $builder): Builder => $builder->where('item_category_id', $categoryId));
        }

        $records = $query->limit($maxRecords + 1)->get();

        abort_if($records->isEmpty(), 404, 'No issuances found for the selected date range.');
        abort_if(
            $records->count() > $maxRecords,
            422,
            'Too many records (max '.$maxRecords.'). Narrow the date range.',
        );

        if ($format === 'pdf') {
            $count = $records->count();
            $packZip = $count > StockLevelExportService::BATCH_SIZE;

            $this->logExportActivity('Exported RSMI report', properties: [
                'count' => $count,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'format' => $packZip ? 'zip' : 'pdf',
                'mode' => 'fast',
                'pack' => $packZip ? 'zip' : 'single',
                'category' => $categoryId > 0 ? $categoryId : null,
            ]);

            if ($packZip) {
                return $this->rsmiFastPdfExport->downloadZip($records);
            }

            return $this->rsmiFastPdfExport->downloadMany($records);
        }

        $this->logExportActivity('Exported RSMI report', properties: [
            'count' => $records->count(),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'format' => $format,
            'category' => $categoryId > 0 ? $categoryId : null,
        ]);

        abort_unless($this->owwaExport->canExportIssuancesAsRsmiWorkbook($records), 422, 'Issuances cannot be exported as RSMI workbook.');

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

    public function procurement(
        Request $request,
        PurchaseRequestFastPdfExportService $prFastPdfExport,
        PurchaseRequestFastExcelExportService $prFastExcelExport,
        PurchaseOrderFastPdfExportService $poFastPdfExport,
        PurchaseOrderFastExcelExportService $poFastExcelExport,
        InspectionAcceptanceReportFastPdfExportService $iarFastPdfExport,
        InspectionAcceptanceReportFastExcelExportService $iarFastExcelExport,
    ): StreamedResponse|Response {
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

        $this->logExportActivity('bulk_procurement_'.$documentType.'_'.$format, null, [
            'count' => $records->count(),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'format' => $format,
            'category' => $categoryId > 0 ? $categoryId : null,
        ]);

        // PR / PO / IAR use coded lookalike exports (not Official template fill).
        return match ($documentType) {
            'po' => $format === 'pdf'
                ? $poFastPdfExport->downloadMany($records)
                : $poFastExcelExport->downloadMany($records),
            'iar' => $format === 'pdf'
                ? $iarFastPdfExport->downloadMany($records)
                : $iarFastExcelExport->downloadMany($records),
            default => $format === 'pdf'
                ? $prFastPdfExport->downloadMany($records)
                : $prFastExcelExport->downloadMany($records),
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
