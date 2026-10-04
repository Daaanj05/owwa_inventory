<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\StockCardExportReadyDatabaseNotification;
use App\Services\StockCardExportStatusService;
use App\Services\StockCardQueuedExportService;
use App\Support\StockCardLedgerDateRange;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\URL;
use Throwable;

class GenerateStockCardExportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $selectedKeys
     */
    public function __construct(
        public int $userId,
        public string $categorySlug,
        public string $format,
        public string $downloadSize,
        public string $scope = 'all',
        public ?int $categoryId = null,
        public ?string $search = null,
        public string $restockFilter = 'active',
        public ?int $scopedOfficeId = null,
        public array $selectedKeys = [],
        public bool $notifyDatabase = true,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
    ) {}

    public function handle(
        StockCardQueuedExportService $exportService,
        StockCardExportStatusService $statusService,
    ): void {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $statusService->markQueued($this->userId, $this->format);

        $range = StockCardLedgerDateRange::resolve(
            ($this->dateFrom !== null || $this->dateTo !== null) ? 'range' : 'all',
            $this->dateFrom,
            $this->dateTo,
        );

        $stored = $exportService->buildAndStore(
            userId: $this->userId,
            categorySlug: $this->categorySlug,
            format: $this->format,
            downloadSize: $this->downloadSize,
            scope: $this->scope,
            categoryId: $this->categoryId,
            search: $this->search,
            restockFilter: $this->restockFilter,
            scopedOfficeId: $this->scopedOfficeId,
            selectedKeys: $this->selectedKeys,
            dateFrom: $range['from'] ?? null,
            dateTo: $range['to'] ?? null,
        );

        $downloadUrl = URL::temporarySignedRoute(
            'owwa.export.stock-cards.download',
            now()->addDay(),
            [
                'user' => $this->userId,
                'file' => basename($stored['path']),
            ],
        );

        $format = $this->format === 'pdf' ? 'pdf' : 'xlsx';
        $isZip = str_ends_with(mb_strtolower($stored['filename']), '.zip');
        $previewUrl = $format === 'pdf' && ! $isZip
            ? URL::temporarySignedRoute(
                'owwa.export.stock-cards.download',
                now()->addDay(),
                [
                    'user' => $this->userId,
                    'file' => basename($stored['path']),
                    'inline' => 1,
                ],
            )
            : null;

        $label = $format === 'pdf' ? 'PDF' : 'Excel';

        $statusService->markReady(
            userId: $this->userId,
            format: $format,
            filename: $stored['filename'],
            downloadUrl: $downloadUrl,
            previewUrl: $previewUrl,
        );

        if (! $this->notifyDatabase) {
            return;
        }

        $user->notify(new StockCardExportReadyDatabaseNotification(
            title: 'Stock card export ready',
            body: "Your {$label} export ({$stored['filename']}) is ready to download.",
            downloadUrl: $downloadUrl,
            previewUrl: $previewUrl,
        ));
    }

    public function failed(Throwable $exception): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        app(StockCardExportStatusService::class)->markFailed($this->userId, $this->format);

        if (! $this->notifyDatabase) {
            return;
        }

        $user->notify(new StockCardExportReadyDatabaseNotification(
            title: 'Stock card export failed',
            body: 'Your background stock card export could not be completed. Try again with fewer positions or Excel format.',
            failed: true,
        ));
    }
}
