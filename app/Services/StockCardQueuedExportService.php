<?php

namespace App\Services;

use App\Support\OwwaExportFilename;
use App\Support\PhpExtensionGuard;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ZipArchive;

class StockCardQueuedExportService
{
    public const string DISK = 'local';

    public const string DIRECTORY = 'exports/stock-cards';

    public function __construct(
        protected StockLevelExportService $stockLevelExport,
        protected OwwaItemReportService $itemReport,
        protected OwwaTemplateExportService $templateExport,
    ) {}

    /**
     * @param  array<int, string>  $selectedKeys
     * @return array{disk: string, path: string, filename: string, mime: string}
     */
    public function buildAndStore(
        int $userId,
        string $categorySlug,
        string $format,
        string $downloadSize,
        string $scope = 'all',
        ?int $categoryId = null,
        ?string $search = null,
        string $restockFilter = 'active',
        ?int $scopedOfficeId = null,
        array $selectedKeys = [],
        ?CarbonInterface $dateFrom = null,
        ?CarbonInterface $dateTo = null,
    ): array {
        $pairs = $this->stockLevelExport->collectPairs(
            categoryId: $categoryId,
            search: $search,
            restockFilter: $restockFilter,
            scopedOfficeId: $scopedOfficeId,
            explicitPairKeys: $scope === 'selected' ? array_values($selectedKeys) : [],
        );

        if ($pairs->isEmpty()) {
            throw new \RuntimeException('No stock positions could be exported for the queued request.');
        }

        $format = $format === 'pdf' ? 'pdf' : 'xlsx';
        $useBatches = $downloadSize === 'batches'
            || $pairs->count() > StockLevelExportService::SINGLE_MAX;

        if ($useBatches) {
            return $this->storeZip($userId, $categorySlug, $format, $pairs, $dateFrom, $dateTo);
        }

        $spreadsheet = $this->buildSpreadsheet($pairs, $categorySlug, $dateFrom, $dateTo);
        $binary = $format === 'pdf'
            ? $this->templateExport->spreadsheetToPdfBinary($spreadsheet)
            : $this->templateExport->spreadsheetToXlsxBinary($spreadsheet);

        $filename = OwwaExportFilename::batch($this->formCode($categorySlug), ext: $format);

        return $this->storeBinary($userId, $filename, $binary, $format);
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>  $pairs
     */
    protected function buildSpreadsheet(
        Collection $pairs,
        string $categorySlug,
        ?CarbonInterface $dateFrom = null,
        ?CarbonInterface $dateTo = null,
    ): Spreadsheet {
        return match ($categorySlug) {
            'ppe' => $this->itemReport->buildPropertyCardBulkSpreadsheet($pairs),
            'semi_expendable' => $this->templateExport->buildAnnexA1Spreadsheet(
                $this->itemReport->buildAnnexA1BulkTabs($pairs),
            ),
            default => $this->itemReport->buildStockCardBulkSpreadsheet($pairs, $dateFrom, $dateTo),
        };
    }

    protected function formCode(string $categorySlug): string
    {
        return match ($categorySlug) {
            'ppe' => 'PC',
            'semi_expendable' => 'AnnexA1',
            default => 'SC',
        };
    }

    /**
     * @param  Collection<int, array{item_id: int, office_id: int, unit_cost: float|null}>  $pairs
     * @return array{disk: string, path: string, filename: string, mime: string}
     */
    protected function storeZip(
        int $userId,
        string $categorySlug,
        string $format,
        Collection $pairs,
        ?CarbonInterface $dateFrom = null,
        ?CarbonInterface $dateTo = null,
    ): array {
        PhpExtensionGuard::ensureZipArchive();

        $chunks = $this->stockLevelExport->chunkPairKeys($pairs);
        $zipFilename = OwwaExportFilename::batch($this->formCode($categorySlug), ext: 'zip');

        $relativeDir = self::DIRECTORY.'/'.$userId;
        Storage::disk(self::DISK)->makeDirectory($relativeDir);
        $relativePath = $relativeDir.'/'.Str::uuid().'-'.$zipFilename;
        $absoluteZip = Storage::disk(self::DISK)->path($relativePath);

        $zip = new ZipArchive;
        if ($zip->open($absoluteZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create stock card export ZIP.');
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

                $spreadsheet = $this->buildSpreadsheet($chunkPairs, $categorySlug, $dateFrom, $dateTo);
                $binary = $format === 'pdf'
                    ? $this->templateExport->spreadsheetToPdfBinary($spreadsheet)
                    : $this->templateExport->spreadsheetToXlsxBinary($spreadsheet);

                $zip->addFromString(sprintf('part-%02d.%s', $index + 1, $format), $binary);
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet, $binary);
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

    public function pruneOlderThanHours(int $hours = 24): int
    {
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists(self::DIRECTORY)) {
            return 0;
        }

        $cutoff = now()->subHours($hours)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles(self::DIRECTORY) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
