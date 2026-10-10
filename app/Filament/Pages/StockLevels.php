<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Transfers\TransferResource;
use App\Jobs\GenerateStockCardExportJob;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\StockPositionRestockFlag;
use App\Services\InventoryStockService;
use App\Services\OwwaItemReportService;
use App\Services\SemiExpendableEulAnalyticsService;
use App\Services\StockCardExportStatusService;
use App\Services\StockLedgerViewService;
use App\Services\StockLevelExportService;
use App\Support\CategoryWizardBreadcrumb;
use App\Support\OwwaExportDiagnostics;
use App\Support\StockCardLedgerDateRange;
use App\Support\UnitCostKey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

class StockLevels extends Page
{
    use StartsOwwaExportBusy;
    use SyncsActiveItemCategory;
    use WithPagination;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|UnitEnum|null $navigationGroup = 'Regional supply';

    protected static ?string $navigationLabel = 'Stock levels';

    protected static ?string $title = 'Stock levels';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.stock-levels';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }

    #[Url]
    public string $sortBy = 'item_name';

    #[Url]
    public string $sortDir = 'asc';

    #[Url]
    public string $search = '';

    #[Url]
    public int|string|null $category = null;

    #[Url]
    public string $restockFilter = 'active';

    public ?ItemCategory $categoryRecord = null;

    /** @var Collection<int, object>|null */
    protected ?Collection $resolvedStockLevels = null;

    /** @var array<string, array<int, string>> */
    protected array $exportPairKeysMemo = [];

    /** @var array<string, int> */
    protected array $exportPairCountMemo = [];

    /** @var array<int, string> */
    public array $selectedKeys = [];

    /**
     * When set (Stock Card modal → Export), selected-scope exports use these keys
     * instead of table checkboxes.
     *
     * @var array<int, string>|null
     */
    public ?array $exportOverridePairKeys = null;

    public bool $preserveExportOverrideOnMount = false;

    public ?string $fastPrintPreviewUrl = null;

    public ?string $fastPrintDownloadUrl = null;

    public bool $exportSizeReady = false;

    public ?int $exportSizeCount = null;

    public string $exportSizeScope = 'all';

    public function mount(): void
    {
        OwwaExportDiagnostics::raiseMemoryLimit('512M');
        OwwaExportDiagnostics::registerOomGuard('filament.pages.stock-levels');

        $categoryId = filled($this->category)
            ? (int) $this->category
            : (int) session('active_item_category_id', 0);

        $categoryId = self::resolveActiveItemCategoryId($categoryId);

        $this->categoryRecord = ItemCategory::query()->find($categoryId);

        if (! $this->categoryRecord) {
            abort(404);
        }

        $this->category = $this->categoryRecord->id;
        session()->put('active_item_category_id', $this->categoryRecord->id);

        if (! in_array($this->restockFilter, ['active', 'inactive'], true)) {
            $this->restockFilter = 'active';
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Stock levels';
    }

    public function getHeading(): string|Htmlable
    {
        $categoryName = $this->categoryRecord?->name;

        return $categoryName
            ? CategoryWizardBreadcrumb::make(
                $categoryName,
                'Stock Levels',
                (int) $this->category,
                $this->categoryRecord?->getTemplateSlug(),
            )
            : 'Stock levels';
    }

    public static function getNavigationLabel(): string
    {
        return 'Stock levels';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function buildExportDownloadsActionGroup(): ActionGroup
    {
        $slug = $this->categoryRecord?->getTemplateSlug() ?? 'consumables';

        $actions = [
            Action::make('coaStockLevel')
                ->label('Summary PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(route('reports.coa.stock-level'))
                ->openUrlInNewTab(false),
        ];

        if ($slug === 'consumables') {
            $actions[] = $this->makeStockCardsFastSyncExportAction();
            $actions[] = $this->makeStockCardsFastExcelExportAction();
        } else {
            $actions[] = $this->makeStockCardsExportAction(
                name: 'exportStockCardsExcel',
                label: match ($slug) {
                    'ppe' => 'Property cards (Excel)',
                    'semi_expendable' => 'Annex A.1 (Excel)',
                    default => 'Stock cards (Excel)',
                },
                format: 'xlsx',
            );
            $actions[] = $this->makeStockCardsExportAction(
                name: 'exportStockCardsPdf',
                label: match ($slug) {
                    'ppe' => 'Property cards (PDF)',
                    'semi_expendable' => 'Annex A.1 (PDF)',
                    default => 'Stock cards (PDF)',
                },
                format: 'pdf',
            );
        }

        if ($this->categoryRecord?->getTemplateSlug() === 'semi_expendable') {
            $actions[] = $this->makeAnnexA4ExportAction(
                name: 'exportAnnexA4Excel',
                label: 'Annex A.4 (Excel)',
                format: 'xlsx',
            );
            $actions[] = $this->makeAnnexA4ExportAction(
                name: 'exportAnnexA4Pdf',
                label: 'Annex A.4 (PDF)',
                format: 'pdf',
            );
        }

        return ActionGroup::make($actions)
            ->label('Export / Download')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->button()
            ->dropdownWidth(Width::MaxContent)
            ->livewire($this);
    }

    /**
     * Filament resolves {name}Action() with zero arguments when mounting group actions.
     */
    public function exportAnnexA4ExcelAction(): Action
    {
        return $this->makeAnnexA4ExportAction(
            name: 'exportAnnexA4Excel',
            label: 'Annex A.4 (Excel)',
            format: 'xlsx',
        );
    }

    public function exportAnnexA4PdfAction(): Action
    {
        return $this->makeAnnexA4ExportAction(
            name: 'exportAnnexA4Pdf',
            label: 'Annex A.4 (PDF)',
            format: 'pdf',
        );
    }

    protected function makeAnnexA4ExportAction(string $name, string $label, string $format): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->action(function () use ($format): void {
                $url = route('owwa.export.bulk.annex-a4', array_filter([
                    'category' => $this->category,
                    'search' => filled($this->search) ? $this->search : null,
                    'restock_filter' => $this->restockFilter !== 'active' ? $this->restockFilter : null,
                    'format' => $format === 'pdf' ? 'pdf' : null,
                ]));

                $this->startOwwaExportDownload(
                    $url,
                    $format === 'pdf' ? 'Preparing PDF export…' : 'Preparing Excel export…',
                    $format === 'pdf'
                        ? 'Building Annex A.4 registry pages…'
                        : 'Building Annex A.4 registry workbook…',
                );
            });
    }

    public function exportStockCardsExcelAction(): Action
    {
        $slug = $this->categoryRecord?->getTemplateSlug() ?? 'consumables';

        return $this->makeStockCardsExportAction(
            name: 'exportStockCardsExcel',
            label: match ($slug) {
                'ppe' => 'Property cards (Excel)',
                'semi_expendable' => 'Annex A.1 (Excel)',
                default => 'Stock cards (Excel)',
            },
            format: 'xlsx',
        );
    }

    public function exportStockCardsPdfAction(): Action
    {
        $slug = $this->categoryRecord?->getTemplateSlug() ?? 'consumables';

        return $this->makeStockCardsExportAction(
            name: 'exportStockCardsPdf',
            label: match ($slug) {
                'ppe' => 'Property cards (PDF)',
                'semi_expendable' => 'Annex A.1 (PDF)',
                default => 'Stock cards (PDF)',
            },
            format: 'pdf',
        );
    }

    public function exportStockCardsFastSyncAction(): Action
    {
        return $this->makeStockCardsFastSyncExportAction();
    }

    public function exportStockCardsFastExcelAction(): Action
    {
        return $this->makeStockCardsFastExcelExportAction();
    }

    protected function makeStockCardsFastExcelExportAction(): Action
    {
        return Action::make('exportStockCardsFastExcel')
            ->label('Stock cards (Excel)')
            ->icon('heroicon-o-table-cells')
            ->color('gray')
            ->modalHeading('Stock cards (Excel)')
            ->modalDescription('Export stock cards as Excel for the selected scope and date range.')
            ->modalSubmitActionLabel('Download')
            ->mountUsing(function (): void {
                $this->prepareExportActionMount();
                $this->scheduleDeferredExportSize();
            })
            ->fillForm(fn (): array => $this->fastExportFormDefaults())
            ->form([
                ...$this->fastExportScopeForm(),
            ])
            ->action(function (array $data, Action $action): void {
                $result = $this->runStockCardsFastExcelExport(
                    scope: (string) ($data['export_scope'] ?? 'all'),
                    dateMode: (string) ($data['date_mode'] ?? 'range'),
                    dateFrom: isset($data['date_from']) ? (string) $data['date_from'] : null,
                    dateTo: isset($data['date_to']) ? (string) $data['date_to'] : null,
                );

                if ($result === 'halted') {
                    $action->halt();
                }
            });
    }

    protected function makeStockCardsFastSyncExportAction(): Action
    {
        return Action::make('exportStockCardsFastSync')
            ->label('Stock cards (PDF)')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->modalHeading('Stock cards (PDF)')
            ->modalWidth(fn (): Width => filled($this->fastPrintPreviewUrl)
                ? Width::SevenExtraLarge
                : Width::TwoExtraLarge)
            ->extraModalWindowAttributes(fn (): array => [
                'class' => filled($this->fastPrintPreviewUrl)
                    ? 'owwa-fast-export-modal owwa-fast-export-modal--with-preview'
                    : 'owwa-fast-export-modal',
            ])
            ->modalSubmitActionLabel(fn (): string => filled($this->fastPrintPreviewUrl)
                ? 'Refresh preview'
                : 'Continue')
            ->modalCancelActionLabel('Close')
            ->stickyModalFooter()
            ->mountUsing(function (): void {
                $this->prepareExportActionMount();
                $this->fastPrintPreviewUrl = null;
                $this->fastPrintDownloadUrl = null;
                $this->scheduleDeferredExportSize();
            })
            ->fillForm(fn (): array => $this->fastExportFormDefaults(includeDelivery: true))
            ->form([
                ...$this->fastExportScopeForm(),
                ...$this->fastExportDeliveryForm(),
                Placeholder::make('fast_print_preview')
                    ->label('')
                    ->visible(fn (): bool => filled($this->fastPrintPreviewUrl))
                    ->content(fn (): HtmlString => new HtmlString(view(
                        'filament.pages.partials.stock-card-fast-preview-modal',
                        ['previewUrl' => $this->fastPrintPreviewUrl],
                    )->render()))
                    ->extraAttributes(['class' => 'owwa-fast-export-preview-slot'])
                    ->columnSpanFull(),
            ])
            ->extraModalFooterActions([
                Action::make('downloadFastExport')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->visible(fn (): bool => filled($this->fastPrintDownloadUrl))
                    ->action(function (Action $action): void {
                        if (! filled($this->fastPrintDownloadUrl)) {
                            return;
                        }

                        $downloadUrl = $this->fastPrintDownloadUrl;
                        $this->fastPrintPreviewUrl = null;
                        $this->fastPrintDownloadUrl = null;

                        $this->startOwwaExportDownload(
                            $downloadUrl,
                            'Downloading export…',
                            'Building stock cards. Large categories may take a few minutes.',
                            300000,
                        );
                        $action->cancelParentActions();
                    }),
            ])
            ->action(function (array $data, Action $action): void {
                $result = $this->runStockCardsFastSyncExport(
                    scope: (string) ($data['export_scope'] ?? 'all'),
                    delivery: (string) ($data['export_delivery'] ?? 'direct'),
                    dateMode: (string) ($data['date_mode'] ?? 'range'),
                    dateFrom: isset($data['date_from']) ? (string) $data['date_from'] : null,
                    dateTo: isset($data['date_to']) ? (string) $data['date_to'] : null,
                );

                if ($result === 'halted' || $result === 'preview') {
                    $action->halt();
                }
            });
    }

    /**
     * @return array{export_scope: string, date_mode: string, date_from: string, date_to: string, export_delivery?: string}
     */
    protected function fastExportFormDefaults(bool $includeDelivery = false): array
    {
        $fiscal = StockCardLedgerDateRange::fiscalYearDefaults();
        $defaults = [
            'export_scope' => $this->defaultExportScope(),
            'date_mode' => 'range',
            'date_from' => $fiscal['date_from'],
            'date_to' => $fiscal['date_to'],
        ];

        if ($includeDelivery) {
            $defaults['export_delivery'] = 'direct';
        }

        return $defaults;
    }

    protected function defaultExportScope(): string
    {
        return ($this->exportOverridePairKeys !== null || $this->selectedKeys !== [])
            ? 'selected'
            : 'all';
    }

    /**
     * @return array<int, string>
     */
    protected function selectedExportPairKeys(): array
    {
        if ($this->exportOverridePairKeys !== null) {
            return array_values($this->exportOverridePairKeys);
        }

        return array_values($this->selectedKeys);
    }

    protected function prepareExportActionMount(): void
    {
        if (! $this->preserveExportOverrideOnMount) {
            $this->exportOverridePairKeys = null;
        }

        $this->preserveExportOverrideOnMount = false;
    }

    public function openLedgerScopedExport(string $exportAction): void
    {
        $arguments = $this->mountedActionArguments();
        $itemId = (int) ($arguments['itemId'] ?? 0);
        $officeId = (int) ($arguments['officeId'] ?? 0);
        $unitCost = isset($arguments['unitCost']) && $arguments['unitCost'] !== null
            ? (float) $arguments['unitCost']
            : null;

        abort_unless($itemId > 0 && $officeId > 0, 404);

        $allowed = [
            'exportStockCardsFastSync',
            'exportStockCardsFastExcel',
            'exportStockCardsExcel',
            'exportStockCardsPdf',
        ];
        abort_unless(in_array($exportAction, $allowed, true), 404);

        $this->exportOverridePairKeys = [
            app(StockLevelExportService::class)->encodePairKey($itemId, $officeId, $unitCost),
        ];
        $this->preserveExportOverrideOnMount = true;

        // Footer actions are nested under the stock card. unmountAction() only
        // pops that button, so the export modal would mount as a child and be
        // discarded. Replace the whole stack so it mounts as its own modal.
        $this->replaceMountedAction($exportAction);
    }

    /**
     * Custom date-mode UI (no Livewire roundtrip): Date range → From/Until → All dates.
     *
     * @return array<int, Hidden|ViewField>
     */
    protected function stockCardMovementDateForm(): array
    {
        $fiscal = StockCardLedgerDateRange::fiscalYearDefaults();

        return [
            Hidden::make('date_from')
                ->default($fiscal['date_from'])
                ->required(fn (Get $get): bool => ($get('date_mode') ?? 'range') === 'range'),
            Hidden::make('date_to')
                ->default($fiscal['date_to'])
                ->required(fn (Get $get): bool => ($get('date_mode') ?? 'range') === 'range')
                ->rules([
                    fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        if (($get('date_mode') ?? 'range') !== 'range') {
                            return;
                        }

                        $from = (string) ($get('date_from') ?? '');
                        $to = (string) ($value ?? '');
                        if ($from !== '' && $to !== '' && $to < $from) {
                            $fail('Until must be on or after From.');
                        }
                    },
                ]),
            ViewField::make('date_mode')
                ->label('Movements to include')
                ->view('filament.pages.partials.stock-card-export-date-mode')
                ->default('range')
                ->required()
                ->rules(['in:range,all'])
                ->helperText('Date range: movements in that range only. Balance forwarded appears when stock was already on hand before the start date.'),
        ];
    }

    /**
     * @return array<int, Hidden|Placeholder|Radio>
     */
    protected function fastExportScopeForm(): array
    {
        $batchSize = StockLevelExportService::BATCH_SIZE;

        return [
            Hidden::make('export_size_stamp')->default('0'),
            Placeholder::make('selection_hint')
                ->label('')
                ->content(function (Get $get): HtmlString {
                    // Depend on stamp so the Placeholder re-renders after deferred count.
                    $get('export_size_stamp');

                    $scope = (string) ($get('export_scope') ?? $this->defaultExportScope());
                    $lines = $this->exportSelectionHintLines($scope, fastLimits: true);

                    return new HtmlString(implode('<br><br>', array_map(
                        fn (string $line): string => e($line),
                        $lines,
                    )));
                })
                ->columnSpanFull(),
            Radio::make('export_scope')
                ->label('Rows to export')
                ->options(fn (): array => $this->exportScopeOptions())
                ->disableOptionWhen(fn (string $value): bool => $this->exportOverridePairKeys !== null && $value === 'all')
                ->helperText("One file if ≤ {$batchSize} positions. Over {$batchSize}: ZIP of multiple files ({$batchSize} each), not one PDF/Excel.")
                ->required()
                ->live()
                ->afterStateUpdated(function (?string $state): void {
                    $this->refreshExportSize($state ?? 'all');
                }),
            ...$this->stockCardMovementDateForm(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function exportScopeOptions(): array
    {
        if ($this->exportOverridePairKeys !== null) {
            return [
                'selected' => 'This Stock Card only',
                'all' => 'All stocks',
            ];
        }

        return [
            'selected' => 'Selected rows only',
            'all' => 'All stocks',
        ];
    }

    /**
     * @return list<string>
     */
    protected function exportSelectionHintLines(string $scope, bool $fastLimits = false): array
    {
        $lines = [];

        if ($this->exportOverridePairKeys !== null) {
            $lines[] = 'Exporting the open Stock Card only (1 stock position). Date range applies below.';
        } elseif ($this->selectedKeys === []) {
            $lines[] = $fastLimits
                ? 'No rows selected. Choose “All stocks” or select rows from the table first.'
                : 'No rows selected. Choose “All stocks” or select rows from the table first. Selections are kept across pages.';
        } else {
            $lines[] = count($this->selectedKeys).' row(s) selected across page(s).';
        }

        if (! $this->exportSizeReady) {
            $lines[] = 'Counting stock positions…';

            return $lines;
        }

        $count = (int) ($this->exportSizeCount ?? 0);
        $batchSize = StockLevelExportService::BATCH_SIZE;

        $lines[] = $scope === 'selected'
            ? "Selected export size: {$count} stock position(s)."
            : "All stocks export size: {$count} stock position(s) (current category, Active/Inactive tab, and search).";

        if ($fastLimits) {
            $lines[] = "Up to {$batchSize} positions: one file downloads in the browser.";

            if ($count > $batchSize) {
                $batchCount = max(1, (int) ceil($count / $batchSize));
                $lines[] = "Over {$batchSize}: downloads as a ZIP of {$batchCount} file(s) ({$batchSize} positions each) — not a single combined PDF/Excel. Stay on this page until the download finishes (may take several minutes).";
            }

            return $lines;
        }

        $singleMax = StockLevelExportService::SINGLE_MAX;
        $lines[] = "Limits: {$batchSize} per batch file, {$singleMax} maximum for one file.";

        if ($count > $batchSize) {
            $lines[] = 'Large exports run in the background. You will get a notification when the file is ready to download.';
        }

        if ($count > $singleMax) {
            $lines[] = "Over {$singleMax}: the background job will pack multiple files of {$batchSize} each into one ZIP.";
        } elseif ($count > $batchSize) {
            $lines[] = 'Choose download size below: one file, or multiple files of '.$batchSize.' each (delivered as one ZIP).';
        }

        return $lines;
    }

    /**
     * @return array<int, Radio>
     */
    protected function fastExportDeliveryForm(string $default = 'direct'): array
    {
        return [
            Radio::make('export_delivery')
                ->label('When ready')
                ->options([
                    'preview' => 'Show print preview, then download',
                    'direct' => 'Download directly in the browser (no preview)',
                ])
                ->default($default)
                ->required()
                ->disableOptionWhen(function (string $value): bool {
                    return $value === 'preview'
                        && $this->exportSizeReady
                        && (int) ($this->exportSizeCount ?? 0) > StockLevelExportService::BATCH_SIZE;
                })
                ->helperText(function (): ?string {
                    if (
                        $this->exportSizeReady
                        && (int) ($this->exportSizeCount ?? 0) > StockLevelExportService::BATCH_SIZE
                    ) {
                        return 'Print preview is unavailable above '.StockLevelExportService::BATCH_SIZE.' positions (ZIP pack download).';
                    }

                    return null;
                }),
        ];
    }

    /**
     * @return 'sync'|'preview'|'halted'
     */
    public function runStockCardsFastSyncExport(
        string $scope,
        string $delivery = 'direct',
        string $dateMode = 'all',
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): string {
        $delivery = $delivery === 'preview' ? 'preview' : 'direct';

        if ($scope === 'selected' && $this->selectedExportPairKeys() === []) {
            Notification::make()
                ->title('No rows selected')
                ->body('Select at least one row from the table, or export all stocks.')
                ->warning()
                ->send();

            return 'halted';
        }

        $count = $this->exportPairCountForScope($scope);

        if ($count === 0) {
            Notification::make()
                ->title('Nothing to export')
                ->body('No stock positions matched the current filters.')
                ->warning()
                ->send();

            return 'halted';
        }

        if ($count > StockLevelExportService::FAST_MAX) {
            Notification::make()
                ->title('Too many stock positions for PDF export')
                ->body('PDF export supports at most '.StockLevelExportService::FAST_MAX.' positions. Narrow filters or select fewer rows.')
                ->warning()
                ->send();

            return 'halted';
        }

        $packZip = $count > StockLevelExportService::BATCH_SIZE;
        $pairKeys = $scope === 'selected' ? $this->selectedExportPairKeys() : null;
        $downloadUrl = $this->buildStockCardsFastExportUrl(
            scope: $scope,
            pairKeys: $pairKeys,
            dateMode: $dateMode,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );

        if ($delivery === 'preview') {
            if ($packZip) {
                Notification::make()
                    ->title('Preview unavailable for ZIP packs')
                    ->body('Over '.StockLevelExportService::BATCH_SIZE.' positions download as a ZIP. Choose “Download directly”.')
                    ->warning()
                    ->send();

                return 'halted';
            }

            $this->fastPrintPreviewUrl = $this->buildStockCardsFastExportUrl(
                scope: $scope,
                pairKeys: $pairKeys,
                previewHtml: true,
                dateMode: $dateMode,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
            );
            $this->fastPrintDownloadUrl = $downloadUrl;

            return 'preview';
        }

        $this->fastPrintPreviewUrl = null;
        $this->fastPrintDownloadUrl = null;

        $batchCount = max(1, (int) ceil($count / StockLevelExportService::BATCH_SIZE));
        $this->startOwwaExportDownload(
            $downloadUrl,
            $packZip ? 'Preparing PDF ZIP…' : 'Preparing PDF export…',
            $packZip
                ? "Building {$batchCount} PDF file(s) (".StockLevelExportService::BATCH_SIZE.' each) into one ZIP. Stay on this page — this may take several minutes.'
                : 'Building stock cards. This should finish in the browser download.',
            300000,
        );

        return 'sync';
    }

    /**
     * @return 'sync'|'halted'
     */
    public function runStockCardsFastExcelExport(
        string $scope,
        string $dateMode = 'all',
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): string {
        if ($scope === 'selected' && $this->selectedExportPairKeys() === []) {
            Notification::make()
                ->title('No rows selected')
                ->body('Select at least one row from the table, or export all stocks.')
                ->warning()
                ->send();

            return 'halted';
        }

        $count = $this->exportPairCountForScope($scope);

        if ($count === 0) {
            Notification::make()
                ->title('Nothing to export')
                ->body('No stock positions matched the current filters.')
                ->warning()
                ->send();

            return 'halted';
        }

        if ($count > StockLevelExportService::FAST_MAX) {
            Notification::make()
                ->title('Too many stock positions for Excel export')
                ->body('Excel export supports at most '.StockLevelExportService::FAST_MAX.' positions. Narrow filters or select fewer rows.')
                ->warning()
                ->send();

            return 'halted';
        }

        $packZip = $count > StockLevelExportService::BATCH_SIZE;
        $pairKeys = $scope === 'selected' ? $this->selectedExportPairKeys() : null;
        $batchCount = max(1, (int) ceil($count / StockLevelExportService::BATCH_SIZE));

        $this->startOwwaExportDownload(
            $this->buildStockCardsFastExcelExportUrl(
                scope: $scope,
                pairKeys: $pairKeys,
                dateMode: $dateMode,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
            ),
            $packZip ? 'Preparing Excel ZIP…' : 'Preparing Excel export…',
            $packZip
                ? "Building {$batchCount} Excel file(s) (".StockLevelExportService::BATCH_SIZE.' each) into one ZIP. Stay on this page — this may take several minutes.'
                : 'Building stock cards. Large categories may take a few minutes.',
            $packZip ? 300000 : 120000,
        );

        return 'sync';
    }

    public function scheduleDeferredExportSize(?string $scope = null): void
    {
        $this->exportSizeReady = false;
        $this->exportSizeCount = null;
        $this->exportSizeScope = $scope ?? $this->defaultExportScope();
        $this->js('queueMicrotask(() => $wire.refreshExportSize())');
    }

    public function refreshExportSize(?string $scope = null): void
    {
        $scope ??= $this->exportSizeScope !== ''
            ? $this->exportSizeScope
            : $this->defaultExportScope();

        if (! in_array($scope, ['selected', 'all'], true)) {
            $scope = 'all';
        }

        $this->exportSizeScope = $scope;
        $this->exportSizeCount = $this->exportPairCountForScope($scope);
        $this->exportSizeReady = true;
        $this->bumpMountedExportSizeStamp();
    }

    protected function bumpMountedExportSizeStamp(): void
    {
        if ($this->mountedActions === [] || $this->mountedActions === null) {
            return;
        }

        $index = array_key_last($this->mountedActions);
        if ($index === null) {
            return;
        }

        if (! isset($this->mountedActions[$index]['data']) || ! is_array($this->mountedActions[$index]['data'])) {
            $this->mountedActions[$index]['data'] = [];
        }

        $this->mountedActions[$index]['data']['export_size_stamp'] = (string) microtime(true);
    }

    /**
     * @param  array<int, string>|null  $pairKeys
     */
    public function buildStockCardsFastExportUrl(
        string $scope,
        ?array $pairKeys = null,
        bool $previewHtml = false,
        string $dateMode = 'all',
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): string {
        $pairs = null;

        if ($pairKeys !== null) {
            $pairs = implode(',', $pairKeys);
        } elseif ($scope === 'selected') {
            $pairs = implode(',', $this->selectedExportPairKeys());
        }

        $params = array_filter([
            'category' => $this->category,
            'search' => filled($this->search) ? $this->search : null,
            'restock_filter' => $this->restockFilter !== 'active' ? $this->restockFilter : null,
            'pairs' => $pairs,
            'preview' => $previewHtml ? 1 : null,
            'export_max' => $previewHtml
                ? StockLevelExportService::BATCH_SIZE
                : StockLevelExportService::FAST_MAX,
            'fast_pack' => $previewHtml ? null : 1,
            ...StockCardLedgerDateRange::toQueryParams($dateMode, $dateFrom, $dateTo),
        ], fn (mixed $value): bool => filled($value));

        return route('owwa.export.bulk.stock-cards-fast', $params);
    }

    /**
     * @param  array<int, string>|null  $pairKeys
     */
    public function buildStockCardsFastExcelExportUrl(
        string $scope,
        ?array $pairKeys = null,
        string $dateMode = 'all',
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): string {
        $pairs = null;

        if ($pairKeys !== null) {
            $pairs = implode(',', $pairKeys);
        } elseif ($scope === 'selected') {
            $pairs = implode(',', $this->selectedExportPairKeys());
        }

        $params = array_filter([
            'category' => $this->category,
            'search' => filled($this->search) ? $this->search : null,
            'restock_filter' => $this->restockFilter !== 'active' ? $this->restockFilter : null,
            'pairs' => $pairs,
            'export_max' => StockLevelExportService::FAST_MAX,
            'fast_pack' => 1,
            ...StockCardLedgerDateRange::toQueryParams($dateMode, $dateFrom, $dateTo),
        ], fn (mixed $value): bool => filled($value));

        return route('owwa.export.bulk.stock-cards-fast-xlsx', $params);
    }

    protected function makeStockCardsExportAction(string $name, string $label, string $format): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->modalHeading($label)
            ->modalSubmitActionLabel('Download')
            ->mountUsing(function (): void {
                $this->prepareExportActionMount();
                $this->scheduleDeferredExportSize();
            })
            ->fillForm(fn (): array => $this->fastExportFormDefaults())
            ->form([
                Hidden::make('export_size_stamp')->default('0'),
                Placeholder::make('selection_hint')
                    ->label('')
                    ->content(function (Get $get): HtmlString {
                        $get('export_size_stamp');
                        $scope = (string) ($get('export_scope') ?? $this->defaultExportScope());
                        $lines = $this->exportSelectionHintLines($scope, fastLimits: false);

                        return new HtmlString(implode('<br><br>', array_map(
                            fn (string $line): string => e($line),
                            $lines,
                        )));
                    })
                    ->columnSpanFull(),
                Radio::make('export_scope')
                    ->label('Rows to export')
                    ->options(fn (): array => $this->exportScopeOptions())
                    ->disableOptionWhen(fn (string $value): bool => $this->exportOverridePairKeys !== null && $value === 'all')
                    ->helperText('All stocks = every position matching the current category, Active/Inactive restock tab, and search. Checkboxes are not required.')
                    ->default(fn (): string => $this->defaultExportScope())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state): void {
                        $this->refreshExportSize($state ?? 'all');
                    }),
                ...$this->stockCardMovementDateForm(),
                Radio::make('download_size')
                    ->label('Download size')
                    ->options(function (): array {
                        $count = (int) ($this->exportSizeCount ?? 0);
                        $batchSize = StockLevelExportService::BATCH_SIZE;
                        $batchCount = max(1, (int) ceil(max(1, $count) / $batchSize));

                        $options = [
                            'batches' => "Multiple files ({$batchSize} each — {$batchCount} file".($batchCount === 1 ? '' : 's').' in one ZIP)',
                        ];

                        if ($count <= StockLevelExportService::SINGLE_MAX) {
                            $options = [
                                'one' => "One file ({$count} positions".($count > $batchSize ? '; background job' : '').')',
                                ...$options,
                            ];
                        }

                        return $options;
                    })
                    ->default(function (): string {
                        $count = (int) ($this->exportSizeCount ?? 0);

                        if ($count > StockLevelExportService::BATCH_SIZE && $count <= StockLevelExportService::SINGLE_MAX) {
                            return 'one';
                        }

                        return $count > StockLevelExportService::SINGLE_MAX ? 'batches' : 'one';
                    })
                    ->required()
                    ->visible(fn (): bool => $this->exportSizeReady
                        && (int) ($this->exportSizeCount ?? 0) > StockLevelExportService::BATCH_SIZE)
                    ->live(),
            ])
            ->action(function (array $data, Action $action) use ($format): void {
                $result = $this->runStockCardsExport(
                    format: $format,
                    scope: (string) ($data['export_scope'] ?? 'all'),
                    downloadSize: (string) ($data['download_size'] ?? 'one'),
                    dateMode: (string) ($data['date_mode'] ?? 'range'),
                    dateFrom: isset($data['date_from']) ? (string) $data['date_from'] : null,
                    dateTo: isset($data['date_to']) ? (string) $data['date_to'] : null,
                );

                if ($result === 'halted') {
                    $action->halt();
                }
            });
    }

    /**
     * @return 'queued'|'sync'|'halted'
     */
    public function runStockCardsExport(
        string $format,
        string $scope,
        string $downloadSize,
        string $dateMode = 'all',
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): string {

        OwwaExportDiagnostics::raiseMemoryLimit('512M');
        OwwaExportDiagnostics::registerOomGuard('filament.pages.stock-levels.export');

        if ($scope === 'selected' && $this->selectedExportPairKeys() === []) {
            Notification::make()
                ->title('No rows selected')
                ->body('Select at least one row from the table, or export all stocks.')
                ->warning()
                ->send();

            return 'halted';
        }

        $count = $this->exportPairCountForScope($scope);

        if ($count === 0) {
            Notification::make()
                ->title('Nothing to export')
                ->body('No stock positions matched the current filters.')
                ->warning()
                ->send();

            return 'halted';
        }

        if ($count > StockLevelExportService::BATCH_SIZE && $downloadSize === '') {
            $downloadSize = $count > StockLevelExportService::SINGLE_MAX ? 'batches' : 'one';
        }

        if ($count <= StockLevelExportService::BATCH_SIZE) {
            $downloadSize = 'one';
        }

        if ($downloadSize === 'one' && $count > StockLevelExportService::SINGLE_MAX) {
            Notification::make()
                ->title('Too many stock positions for one file')
                ->body('One file supports at most '.StockLevelExportService::SINGLE_MAX.' positions. Choose multiple files instead.')
                ->warning()
                ->send();

            return 'halted';
        }

        if ($count > StockLevelExportService::BATCH_SIZE) {
            $user = Filament::auth()->user();
            if ($user === null) {
                return 'halted';
            }

            $scopedOfficeId = $user->office_id ? (int) $user->office_id : null;

            app(StockCardExportStatusService::class)->markQueued((int) $user->id, $format);

            $dateParams = StockCardLedgerDateRange::toQueryParams($dateMode, $dateFrom, $dateTo);

            Queue::push(new GenerateStockCardExportJob(
                userId: (int) $user->id,
                categorySlug: $this->categoryRecord?->getTemplateSlug() ?? 'consumables',
                format: $format,
                downloadSize: $downloadSize,
                scope: $scope,
                categoryId: $this->category !== null ? (int) $this->category : null,
                search: filled($this->search) ? $this->search : null,
                restockFilter: $this->restockFilter,
                scopedOfficeId: $scopedOfficeId,
                selectedKeys: $scope === 'selected' ? $this->selectedExportPairKeys() : [],
                dateFrom: $dateParams['date_from'] ?? null,
                dateTo: $dateParams['date_to'] ?? null,
            ));

            OwwaExportDiagnostics::info('stock_levels_export_queued', [
                'scope' => $scope,
                'format' => $format,
                'download_size' => $downloadSize,
                'category' => $this->category,
                'export_count' => $count,
            ]);

            Notification::make()
                ->title('Export started in the background')
                ->body('You will get a notification when the file is ready to download.')
                ->success()
                ->send();

            return 'queued';
        }

        $urls = [$this->buildStockCardsExportUrl(
            scope: $scope,
            format: $format,
            pairKeys: $scope === 'selected' ? $this->selectedExportPairKeys() : null,
            dateMode: $dateMode,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        )];

        OwwaExportDiagnostics::info('stock_levels_export_action', [
            'scope' => $scope,
            'format' => $format,
            'download_size' => $downloadSize,
            'category' => $this->category,
            'selected_count' => count($this->selectedExportPairKeys()),
            'export_count' => $count,
            'url_count' => count($urls),
            'url' => $urls[0] ?? null,
            'memory_limit' => ini_get('memory_limit'),
        ]);

        $this->startOwwaExportDownloads(
            urls: $urls,
            title: $format === 'pdf' ? 'Preparing PDF export…' : 'Preparing Excel export…',
            message: $format === 'pdf'
                ? 'Building OWWA form pages. Large selections can take a little while.'
                : 'Building your workbook. Large selections can take a little while.',
            autoClearMs: $format === 'pdf' ? 180000 : 120000,
        );

        return 'sync';
    }

    /**
     * @return array<int, string>
     */
    public function exportPairKeysForScope(string $scope): array
    {
        if ($scope === 'selected') {
            return $this->selectedExportPairKeys();
        }

        $memoKey = implode('|', [
            $scope,
            (string) ($this->category ?? ''),
            (string) $this->search,
            $this->restockFilter,
        ]);

        if (array_key_exists($memoKey, $this->exportPairKeysMemo)) {
            return $this->exportPairKeysMemo[$memoKey];
        }

        $service = app(StockLevelExportService::class);
        $user = Filament::auth()->user();
        $scopedOfficeId = $user?->office_id ? (int) $user->office_id : null;

        return $this->exportPairKeysMemo[$memoKey] = $service->collectPairs(
            categoryId: $this->category !== null ? (int) $this->category : null,
            search: filled($this->search) ? $this->search : null,
            restockFilter: $this->restockFilter,
            scopedOfficeId: $scopedOfficeId,
        )->map(fn (array $pair): string => $service->encodePairKey(
            $pair['item_id'],
            $pair['office_id'],
            $pair['unit_cost'],
        ))->values()->all();
    }

    public function exportPairCountForScope(string $scope): int
    {
        if ($scope === 'selected') {
            return count($this->selectedExportPairKeys());
        }

        $memoKey = implode('|', [
            'count',
            $scope,
            (string) ($this->category ?? ''),
            (string) $this->search,
            $this->restockFilter,
        ]);

        if (array_key_exists($memoKey, $this->exportPairCountMemo)) {
            return $this->exportPairCountMemo[$memoKey];
        }

        $service = app(StockLevelExportService::class);
        $user = Filament::auth()->user();
        $scopedOfficeId = $user?->office_id ? (int) $user->office_id : null;

        return $this->exportPairCountMemo[$memoKey] = $service->countPairs(
            categoryId: $this->category !== null ? (int) $this->category : null,
            search: filled($this->search) ? $this->search : null,
            restockFilter: $this->restockFilter,
            scopedOfficeId: $scopedOfficeId,
        );
    }

    /**
     * @param  array<int, string>|null  $pairKeys
     */
    public function buildStockCardsExportUrl(
        string $scope,
        string $format,
        ?int $exportMax = null,
        ?array $pairKeys = null,
        string $dateMode = 'all',
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): string {
        $pairs = null;

        if ($pairKeys !== null) {
            $pairs = implode(',', $pairKeys);
        } elseif ($scope === 'selected') {
            $pairs = implode(',', $this->selectedExportPairKeys());
        }

        $params = array_filter([
            'category' => $this->category,
            'search' => filled($this->search) ? $this->search : null,
            'restock_filter' => $this->restockFilter !== 'active' ? $this->restockFilter : null,
            'format' => $format === 'pdf' ? 'pdf' : null,
            'pairs' => $pairs,
            'export_max' => $exportMax !== null && $exportMax > StockLevelExportService::BATCH_SIZE
                ? $exportMax
                : null,
            ...StockCardLedgerDateRange::toQueryParams($dateMode, $dateFrom, $dateTo),
        ], fn (mixed $value): bool => filled($value));

        return route('owwa.export.bulk.stock-cards', $params);
    }

    /**
     * @param  array<int, string>  $pairKeys
     * @return array<int, string>
     */
    public function buildStockCardsBatchExportUrls(array $pairKeys, string $format): array
    {
        $chunks = app(StockLevelExportService::class)->chunkPairKeys($pairKeys);

        return array_map(
            fn (array $chunk): string => $this->buildStockCardsExportUrl(
                scope: 'selected',
                format: $format,
                pairKeys: $chunk,
            ),
            $chunks,
        );
    }

    public function pairKeyForRow(object $row): string
    {
        return app(StockLevelExportService::class)->encodePairKey(
            (int) $row->item_id,
            (int) $row->office_id,
            null,
        );
    }

    /**
     * @return array<int, string>
     */
    public function currentPagePairKeys(): array
    {
        return $this->getStockLevels()
            ->getCollection()
            ->map(fn (object $row): string => $this->pairKeyForRow($row))
            ->values()
            ->all();
    }

    public function toggleRowSelection(string $key): void
    {
        if (in_array($key, $this->selectedKeys, true)) {
            $this->selectedKeys = array_values(array_filter(
                $this->selectedKeys,
                fn (string $selected): bool => $selected !== $key,
            ));

            return;
        }

        $this->selectedKeys[] = $key;
    }

    public function toggleSelectAllOnPage(): void
    {
        $pageKeys = $this->currentPagePairKeys();

        $allSelected = $pageKeys !== []
            && collect($pageKeys)->every(fn (string $key): bool => in_array($key, $this->selectedKeys, true));

        if ($allSelected) {
            $this->selectedKeys = array_values(array_filter(
                $this->selectedKeys,
                fn (string $key): bool => ! in_array($key, $pageKeys, true),
            ));

            return;
        }

        $this->selectedKeys = array_values(array_unique([
            ...$this->selectedKeys,
            ...$pageKeys,
        ]));
    }

    public function isRowSelected(string $key): bool
    {
        return in_array($key, $this->selectedKeys, true);
    }

    public function isAllOnPageSelected(): bool
    {
        $pageKeys = $this->currentPagePairKeys();

        return $pageKeys !== []
            && collect($pageKeys)->every(fn (string $key): bool => in_array($key, $this->selectedKeys, true));
    }

    public function getSelectedCount(): int
    {
        return count($this->selectedKeys);
    }

    public function clearSelection(): void
    {
        $this->selectedKeys = [];
    }

    public function getMissingPropertyClassCount(): int
    {
        if ($this->categoryRecord?->getTemplateSlug() !== 'semi_expendable') {
            return 0;
        }

        return app(OwwaItemReportService::class)->countStockLevelItemsMissingPropertyClass(
            (int) $this->category,
            filled($this->search) ? $this->search : null,
        );
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        if (! $this->categoryRecord) {
            return ['owwa-inv-category-page'];
        }

        return [
            'owwa-inv-category-page',
            'owwa-icd--'.Str::slug($this->categoryRecord->name),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resolvedStockLevels = null;
        $this->resetPage();
        $this->selectedKeys = [];
    }

    public function setRestockFilter(string $filter): void
    {
        if (! in_array($filter, ['active', 'inactive'], true)) {
            return;
        }

        $this->resolvedStockLevels = null;
        $this->restockFilter = $filter;
        $this->resetPage();
        $this->selectedKeys = [];
    }

    public function sortByColumn(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    public function usefulLifeDueCount(): int
    {
        if ($this->categoryRecord?->getTemplateSlug() !== 'semi_expendable') {
            return 0;
        }

        $officeId = Filament::auth()->user()?->office_id;

        return app(SemiExpendableEulAnalyticsService::class)
            ->dueRows($officeId !== null ? [(int) $officeId] : [])
            ->count();
    }

    /** @return array{total: int, totalStockQty: int, lowCount: int, okCount: int} */
    public function getStockLevelsSummary(): array
    {
        $rows = $this->getStockLevelsFull();
        $total = $rows->count();
        $lowCount = $rows->where('is_low', true)->count();

        return [
            'total' => $total,
            'totalStockQty' => (int) $rows->sum('stock'),
            'lowCount' => $lowCount,
            'okCount' => $total - $lowCount,
        ];
    }

    /** @return Collection<int, object> */
    public function getStockLevelsFull(): Collection
    {
        if ($this->resolvedStockLevels !== null) {
            return $this->resolvedStockLevels;
        }

        $categoryId = $this->categoryRecord?->id;
        $user = Filament::auth()->user();
        $officeId = $user?->office_id;
        $rows = app(InventoryStockService::class)->getStockLevelsList(
            $categoryId !== null ? (int) $categoryId : null,
            $officeId !== null ? (int) $officeId : null,
        );

        if (filled($this->search)) {
            $term = mb_strtolower($this->search);
            $rows = $rows->filter(fn (object $r): bool => str_contains(mb_strtolower($r->item_name ?? ''), $term)
                || str_contains(mb_strtolower($r->office_name ?? ''), $term)
            )->values();
        }

        $rows = $rows->filter(fn (object $row): bool => $this->matchesRestockFilter($row))->values();

        $rows = app(InventoryStockService::class)->summarizeStockLevelsByItemOffice($rows, includeLatestUnitCost: false);

        return $this->resolvedStockLevels = $rows;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, int>
     */
    protected function taggedUnitCountsForRows(\Illuminate\Support\Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $itemIds = $rows->pluck('item_id')->unique()->values();
        $officeIds = $rows->pluck('office_id')->unique()->values();

        $counts = InventoryUnit::query()
            ->selectRaw('item_id, office_id, count(*) as tagged_units')
            ->whereIn('item_id', $itemIds)
            ->whereIn('office_id', $officeIds)
            ->whereIn('status', InventoryUnit::accountableStatuses())
            ->groupBy('item_id', 'office_id')
            ->get();

        $result = [];
        foreach ($counts as $count) {
            $key = (int) $count->item_id.'_'.(int) $count->office_id;
            $result[$key] = (int) $count->tagged_units;
        }

        return $result;
    }

    public function usesTaggedUnitsColumn(): bool
    {
        return in_array($this->categoryRecord?->getTemplateSlug(), ['ppe', 'semi_expendable'], true);
    }

    public function shouldShowSupplyCustodianScopeFilters(): bool
    {
        return false;
    }

    public function getStockLevels(): LengthAwarePaginator
    {
        $rows = $this->getStockLevelsFull();

        $sortBy = $this->sortBy;
        $sortDir = $this->sortDir;
        $rows = $rows->sortBy($sortBy, SORT_REGULAR, $sortDir === 'desc')->values();

        $perPage = 10;
        $page = $this->getPage();
        $pageRows = $this->enrichStockLevelsPage(
            $rows->forPage($page, $perPage)->values(),
        );

        return (new LengthAwarePaginator(
            $pageRows,
            $rows->count(),
            $perPage,
            $page,
            ['path' => $this->stockLevelsPaginationPath()],
        ))
            ->appends($this->stockLevelsPaginationAppends())
            ->onEachSide(0);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    protected function enrichStockLevelsPage(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $latestUnitCosts = app(InventoryStockService::class)->latestUnitCostsForStockRows($rows);
        $taggedCounts = $this->usesTaggedUnitsColumn()
            ? $this->taggedUnitCountsForRows($rows)
            : [];

        return $rows->map(function (object $row) use ($latestUnitCosts, $taggedCounts): object {
            $pairKey = (int) $row->item_id.'_'.(int) $row->office_id;
            $avg = $row->avg_unit_cost ?? $row->unit_cost;
            $row->latest_unit_cost = ($latestUnitCosts[$pairKey] ?? null) ?? $avg;

            if ($taggedCounts !== []) {
                $row->accountable_tags = (int) ($taggedCounts[$pairKey] ?? 0);
                $row->tagged_units = $row->accountable_tags;
                $row->tagged_drift = $row->accountable_tags < (int) $row->stock;
            }

            return $row;
        })->values();
    }

    protected function stockLevelsPaginationPath(): string
    {
        return static::getUrl(['category' => $this->category]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function stockLevelsPaginationAppends(): array
    {
        return array_filter([
            'category' => $this->category,
            'sortBy' => $this->sortBy,
            'sortDir' => $this->sortDir,
            'search' => filled($this->search) ? $this->search : null,
            'restockFilter' => $this->restockFilter !== 'active' ? $this->restockFilter : null,
        ], fn (mixed $value): bool => filled($value));
    }

    protected function matchesRestockFilter(object $row): bool
    {
        $isInactive = (bool) ($row->is_inactive_for_restock ?? false);

        return $this->restockFilter === 'inactive' ? $isInactive : ! $isInactive;
    }

    public function openStockLedger(int $itemId, int $officeId, float|string|null $unitCost = null): void
    {
        $parsedCost = $unitCost !== null && $unitCost !== '' ? (float) $unitCost : null;

        try {
            app(StockLedgerViewService::class)->assertCanOpenLedger(
                $itemId,
                $officeId,
                $parsedCost,
                $this->categoryRecord?->id !== null ? (int) $this->categoryRecord->id : null,
            );
        } catch (AuthorizationException) {
            abort(403);
        }

        $item = Item::query()->with('category')->findOrFail($itemId);
        $office = Office::query()->findOrFail($officeId);
        $ledgerService = app(StockLedgerViewService::class);

        $requestKey = $itemId.'|'.$officeId.'|'.($parsedCost === null ? 'null' : UnitCostKey::normalize($parsedCost));
        $userId = (int) (Filament::auth()->id() ?? 0);
        $cacheKey = "stock-ledger-modal:{$userId}:{$requestKey}";

        $this->resolvedMountedLedgerKey = $requestKey;
        $this->resolvedMountedLedger = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($item, $office, $parsedCost, $requestKey, $itemId, $officeId, $ledgerService): array {
            OwwaExportDiagnostics::info('stock_ledger_modal_resolve', [
                'cache_key' => $requestKey,
                'item_id' => $itemId,
                'office_id' => $officeId,
                'unit_cost' => $parsedCost,
            ]);

            return $ledgerService->present($item, $office, $parsedCost);
        });

        $this->mountAction('viewStockLedger', [
            'itemId' => $itemId,
            'officeId' => $officeId,
            'unitCost' => $parsedCost,
        ]);
    }

    public function toggleRestockInactive(int $itemId, int $officeId, float|string $unitCost): void
    {
        $user = Filament::auth()->user();
        if (! $user || ($user->office_id && (int) $user->office_id !== $officeId)) {
            abort(403);
        }

        StockPositionRestockFlag::markInactive(
            $itemId,
            $officeId,
            (float) $unitCost,
            (int) $user->id,
        );

        \Filament\Notifications\Notification::make()
            ->title('Marked inactive for restock')
            ->body('This cost position remains in inventory but is excluded from procurement cover.')
            ->success()
            ->send();
    }

    public function toggleRestockActive(int $itemId, int $officeId, float|string $unitCost): void
    {
        $user = Filament::auth()->user();
        if (! $user || ($user->office_id && (int) $user->office_id !== $officeId)) {
            abort(403);
        }

        $stock = app(\App\Services\InventoryStockService::class)
            ->getStockForUnitCost($itemId, $officeId, (float) $unitCost);
        $flag = StockPositionRestockFlag::findForPosition($itemId, $officeId, (float) $unitCost);
        $snooze = $stock <= 0 && $flag?->inactive_source === StockPositionRestockFlag::SOURCE_AUTOMATIC;

        StockPositionRestockFlag::markActive(
            $itemId,
            $officeId,
            (float) $unitCost,
            snoozeAutomaticIfStillZero: $snooze,
        );

        \Filament\Notifications\Notification::make()
            ->title('Marked active for restock')
            ->success()
            ->send();
    }

    public function getTransferPrefillUrl(int $itemId, int $officeId, float|string|null $unitCost = null): string
    {
        return TransferResource::getUrl('index', array_filter([
            'create' => 1,
            'item_id' => $itemId,
            'from_office' => $officeId,
            'category' => (int) $this->category,
            'unit_cost' => $unitCost !== null && $unitCost !== '' ? (float) $unitCost : null,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }

    public function canCreateTransfer(): bool
    {
        if ($this->categoryRecord?->getTemplateSlug() === 'consumables') {
            return false;
        }

        return TransferResource::canViewAny();
    }

    /** @var array<string, mixed>|null */
    protected ?array $resolvedMountedLedger = null;

    protected ?string $resolvedMountedLedgerKey = null;

    public function viewStockLedgerAction(): Action
    {
        return Action::make('viewStockLedger')
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->stickyModalFooter()
            ->modalHeading(function (): string {
                $ledger = $this->resolveMountedLedger();

                return $ledger['title'].' — '.$ledger['header']['item_name'];
            })
            ->modalContent(fn (): HtmlString => new HtmlString(view(
                'filament.pages.partials.stock-ledger-modal',
                ['ledger' => $this->resolveMountedLedger()],
            )->render()))
            ->extraModalFooterActions(function (): array {
                $slug = $this->categoryRecord?->getTemplateSlug() ?? 'consumables';

                if ($slug === 'consumables') {
                    return [
                        Action::make('exportLedgerFastPdf')
                            ->label('Export PDF')
                            ->icon('heroicon-o-document-text')
                            ->color('primary')
                            ->action(function (): void {
                                $this->openLedgerScopedExport('exportStockCardsFastSync');
                            }),
                        Action::make('exportLedgerFastExcel')
                            ->label('Export Excel')
                            ->icon('heroicon-o-table-cells')
                            ->color('primary')
                            ->action(function (): void {
                                $this->openLedgerScopedExport('exportStockCardsFastExcel');
                            }),
                    ];
                }

                return [
                    Action::make('exportLedgerExcel')
                        ->label(match ($slug) {
                            'ppe' => 'Export Property Card (Excel)',
                            'semi_expendable' => 'Export Annex A.1 (Excel)',
                            default => 'Export Excel',
                        })
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('primary')
                        ->action(function (): void {
                            $this->openLedgerScopedExport('exportStockCardsExcel');
                        }),
                    Action::make('exportLedgerPdf')
                        ->label(match ($slug) {
                            'ppe' => 'Export Property Card (PDF)',
                            'semi_expendable' => 'Export Annex A.1 (PDF)',
                            default => 'Export PDF',
                        })
                        ->icon('heroicon-o-document-text')
                        ->color('primary')
                        ->action(function (): void {
                            $this->openLedgerScopedExport('exportStockCardsPdf');
                        }),
                ];
            });
    }

    /**
     * @return array{
     *     title: string,
     *     exportForm: string,
     *     exportLabel: string,
     *     exportUrl: string,
     *     exportPdfLabel: string,
     *     exportPdfUrl: string,
     *     header: array<string, string|null>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    protected function resolveMountedLedger(): array
    {
        $arguments = $this->mountedActionArguments();
        $itemId = (int) ($arguments['itemId'] ?? 0);
        $officeId = (int) ($arguments['officeId'] ?? 0);
        $unitCost = isset($arguments['unitCost']) && $arguments['unitCost'] !== null
            ? (float) $arguments['unitCost']
            : null;

        $requestKey = $itemId.'|'.$officeId.'|'.($unitCost === null ? 'null' : UnitCostKey::normalize($unitCost));

        if ($this->resolvedMountedLedgerKey === $requestKey && $this->resolvedMountedLedger !== null) {
            return $this->resolvedMountedLedger;
        }

        $userId = (int) (Filament::auth()->id() ?? 0);
        $cacheKey = "stock-ledger-modal:{$userId}:{$requestKey}";

        $this->resolvedMountedLedgerKey = $requestKey;
        $this->resolvedMountedLedger = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($itemId, $officeId, $unitCost, $requestKey): array {
            OwwaExportDiagnostics::info('stock_ledger_modal_resolve', [
                'cache_key' => $requestKey,
                'item_id' => $itemId,
                'office_id' => $officeId,
                'unit_cost' => $unitCost,
            ]);

            $item = Item::query()->with('category')->findOrFail($itemId);
            $office = Office::query()->findOrFail($officeId);

            return app(StockLedgerViewService::class)->present($item, $office, $unitCost);
        });

        return $this->resolvedMountedLedger;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mountedActionArguments(): array
    {
        // Prefer the root ledger action — nested footer export actions remount on top.
        $parent = $this->getMountedAction(0);
        if ($parent !== null) {
            $arguments = $parent->getArguments();
            if (isset($arguments['itemId'], $arguments['officeId'])) {
                return $arguments;
            }
        }

        return $this->getMountedAction()?->getArguments() ?? [];
    }
}
