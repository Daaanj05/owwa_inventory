<?php

namespace App\Services;

use App\Support\OwwaExportFilename;
use App\Support\PhpExtensionGuard;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Fast Stock Card ZIP packing (DomPDF / PhpSpreadsheet).
 * Chunks into BATCH_SIZE parts — used for sync ZIP download when count > BATCH_SIZE.
 */
class StockCardFastQueuedExportService
{
    public const string DISK = StockCardQueuedExportService::DISK;

    public const string DIRECTORY = StockCardQueuedExportService::DIRECTORY;

    public function __construct(
        protected StockLevelExportService $stockLevelExport,
        protected StockCardFastPdfExportService $fastPdfExport,
        protected StockCardFastExcelExportService $fastExcelExport,
    ) {}

    /**
     * @param  array<int, string>  $selectedKeys
     * @return array{disk: string, path: string, filename: string, mime: string}
     */
    public function buildAndStore(
        int $userId,
        string $format,
        string $scope = 'all',
        ?int $categoryId = null,
        ?string $search = null,
        string $restockFilter = 'active',
        ?int $scopedOfficeId = null,
        array $selectedKeys = [],
    ): array {
        $pairs = $this->stockLevelExport->collectPairs(
            categoryId: $categoryId,
            search: $search,
            restockFilter: $restockFilter,
            scopedOfficeId: $scopedOfficeId,
            explicitPairKeys: $scope === 'selected' ? array_values($selectedKeys) : [],
        );

        if ($pairs->isEmpty()) {
            throw new \RuntimeException('No stock positions could be exported for the queued Fast request.');
        }

        $format = $format === 'xlsx' ? 'xlsx' : 'pdf';

        if ($pairs->count() <= StockLevelExportService::BATCH_SIZE) {
            return $this->storeSingle($userId, $format, $pairs);
        }

        return $this->storeZip($userId, $format, $pairs);
    }

    /**
     * Sync browser download: ZIP of Fast parts (BATCH_SIZE cards each).
     *
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost?: float|null}>  $pairs
     */
    public function downloadZip(
        Collection $pairs,
        string $format,
        ?CarbonInterface $dateFrom = null,
        ?CarbonInterface $dateTo = null,
    ): BinaryFileResponse {
        $format = $format === 'xlsx' ? 'xlsx' : 'pdf';
        $userId = (int) (auth()->id() ?: 0);
        $stored = $this->storeZip($userId > 0 ? $userId : 0, $format, $pairs, $dateFrom, $dateTo);
        $absolute = Storage::disk($stored['disk'])->path($stored['path']);

        return response()
            ->download($absolute, $stored['filename'], [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>  $pairs
     * @return array{disk: string, path: string, filename: string, mime: string}
     */
    protected function storeSingle(
        int $userId,
        string $format,
        Collection $pairs,
        ?CarbonInterface $dateFrom = null,
        ?CarbonInterface $dateTo = null,
    ): array {
        $binary = $this->binaryForPairs($format, $pairs, $dateFrom, $dateTo);
        $filename = OwwaExportFilename::batch('SC-fast', ext: $format);

        return $this->storeBinary($userId, $filename, $binary, $format);
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>  $pairs
     * @return array{disk: string, path: string, filename: string, mime: string}
     */
    protected function storeZip(
        int $userId,
        string $format,
        Collection $pairs,
        ?CarbonInterface $dateFrom = null,
        ?CarbonInterface $dateTo = null,
    ): array {
        PhpExtensionGuard::ensureZipArchive();

        $chunks = $this->stockLevelExport->chunkPairKeys($pairs);
        $zipFilename = OwwaExportFilename::batch('SC-fast', ext: 'zip');

        $relativeDir = self::DIRECTORY.'/'.$userId;
        Storage::disk(self::DISK)->makeDirectory($relativeDir);
        $relativePath = $relativeDir.'/'.Str::uuid().'-'.$zipFilename;
        $absoluteZip = Storage::disk(self::DISK)->path($relativePath);

        $zip = new ZipArchive;
        if ($zip->open($absoluteZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create Fast stock card export ZIP.');
        }

        try {
            foreach ($chunks as $index => $chunkKeys) {
                $chunkPairs = $this->stockLevelExport->collectPairs(
                    categoryId: null,
                    search: null,
                    restockFilter: 'active',
                    scopedOfficeId: null,
                    explicitPairKeys: $chunkKeys,
                );
                if ($chunkPairs->isEmpty()) {
                    continue;
                }

                $binary = $this->binaryForPairs($format, $chunkPairs, $dateFrom, $dateTo);
                $zip->addFromString(sprintf('part-%02d.%s', $index + 1, $format), $binary);
                unset($binary, $chunkPairs);
                gc_collect_cycles();
            }
        } finally {
            $zip->close();
        }

        return [
            'disk' => self::DISK,
            'path' => $relativePath,
            'filename' => $zipFilename,
            'mime' => 'application/zip',
        ];
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost?: float|null}>  $pairs
     */
    protected function binaryForPairs(
        string $format,
        Collection $pairs,
        ?CarbonInterface $dateFrom = null,
        ?CarbonInterface $dateTo = null,
    ): string {
        $cards = $this->fastPdfExport->buildCards($pairs, $dateFrom, $dateTo);

        if ($cards === []) {
            throw new \RuntimeException('No Fast stock cards could be built for a queued chunk.');
        }

        if ($format === 'xlsx') {
            return $this->fastExcelExport->xlsxBinary($cards);
        }

        return $this->fastPdfExport->pdfBinary($cards);
    }

    /**
     * @return array{disk: string, path: string, filename: string, mime: string}
     */
    protected function storeBinary(int $userId, string $filename, string $binary, string $format): array
    {
        $relativeDir = self::DIRECTORY.'/'.$userId;
        Storage::disk(self::DISK)->makeDirectory($relativeDir);
        $relativePath = $relativeDir.'/'.Str::uuid().'-'.$filename;
        Storage::disk(self::DISK)->put($relativePath, $binary);

        return [
            'disk' => self::DISK,
            'path' => $relativePath,
            'filename' => $filename,
            'mime' => $format === 'pdf'
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }
}
