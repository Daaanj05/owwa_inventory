<?php

namespace App\Filament\Resources\Items\Actions;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Items\Support\ItemOpeningStockFields;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\ItemCategory;
use App\Models\User;
use App\Services\ImportConsumableItemsService;
use App\Support\ConsumableItemSpreadsheetReader;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemImportAction
{
    public const RESULTS_PREVIEW_LIMIT = 40;

    public static function make(): Action
    {
        return OwwaFormModalDefaults::apply(
            Action::make('importConsumableItems')
                ->label('Import')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading(fn (): string => 'Import '.self::categoryImportHeadingLabel())
                ->modalDescription(fn (): string => self::importModalDescription())
                ->modalSubmitActionLabel('Import')
                ->schema([
                    Placeholder::make('format_help')
                        ->hiddenLabel()
                        ->content(fn (): HtmlString => new HtmlString(self::importHelpHtml())),
                    FileUpload::make('file')
                        ->label('Spreadsheet')
                        ->required()
                        ->storeFiles(false)
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                            'text/plain',
                            'application/csv',
                        ])
                        ->helperText('Accepts .xlsx, .xls, or .csv.'),
                ])
                ->extraModalFooterActions(fn (Action $action): array => [
                    Action::make('downloadSample')
                        ->label('Download sample file')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('gray')
                        ->action(fn (): StreamedResponse => self::sampleDownloadResponse()),
                ])
                ->action(function (array $data, Action $action): void {
                    if (! self::isImportableCategory()) {
                        Notification::make()
                            ->title('Import is not available for this category')
                            ->danger()
                            ->send();

                        return;
                    }

                    $file = $data['file'] ?? null;
                    $absolutePath = self::resolveUploadedSpreadsheetPath($file);

                    if ($absolutePath === null) {
                        Notification::make()
                            ->title('Upload a spreadsheet file')
                            ->danger()
                            ->send();

                        return;
                    }

                    $categoryId = self::currentCategoryId();
                    $officeId = ItemOpeningStockFields::resolveRegionalOfficeId();
                    $user = auth()->user();

                    try {
                        $result = app(ImportConsumableItemsService::class)->importFromPath(
                            $absolutePath,
                            $categoryId,
                            $officeId,
                            $user instanceof User ? $user : null,
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Unable to import items')
                            ->body(collect($exception->errors())->flatten()->first() ?? 'Validation failed.')
                            ->danger()
                            ->send();

                        throw $exception;
                    }

                    self::sendSummaryNotification($result);

                    $livewire = $action->getLivewire();
                    if ($livewire === null) {
                        return;
                    }

                    if (property_exists($livewire, 'importConsumableResult')) {
                        $livewire->importConsumableResult = $result;
                    }

                    // Replace (do not nest): nesting looks for a child modal action of Import and fails silently.
                    $livewire->replaceMountedAction('importConsumableResults');
                }),
            OwwaFormModalDefaults::WIDTH_COMPACT,
        );
    }

    public static function resultsAction(): Action
    {
        return OwwaFormModalDefaults::apply(
            Action::make('importConsumableResults')
                ->label('Import results')
                ->modalHeading('Import results')
                ->modal()
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->schema(fn (): array => [])
                ->action(fn (): null => null),
            '2xl',
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function resultsSchema(array $result): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $result['rows'] ?? [];

        $successStatuses = ['created', 'stock_filled'];
        $updatedStatuses = ['updated'];
        $skippedStatuses = ['skipped_has_stock', 'skipped_existing', 'skipped_duplicate'];
        $invalidStatuses = ['invalid'];

        $successRows = self::rowsForStatuses($rows, $successStatuses);
        $updatedRows = self::rowsForStatuses($rows, $updatedStatuses);
        $skippedRows = self::rowsForStatuses($rows, $skippedStatuses);
        $invalidRows = self::rowsForStatuses($rows, $invalidStatuses);

        $successCount = count($successRows);
        $updatedCount = count($updatedRows);
        $skippedCount = count($skippedRows);
        $invalidCount = count($invalidRows);

        $components = [
            Placeholder::make('import_results_summary')
                ->hiddenLabel()
                ->content(fn (): HtmlString => self::summaryHtml(
                    created: (int) ($result['created'] ?? 0),
                    updatedCount: $updatedCount,
                    skippedCount: $skippedCount,
                    invalidCount: $invalidCount,
                )),
        ];

        $activeTab = match (true) {
            $invalidCount > 0 => 4,
            $skippedCount > 0 => 3,
            $updatedCount > 0 => 2,
            default => 1,
        };

        $components[] = Tabs::make('Import outcome')
            ->key('import-consumable-results-tabs')
            ->activeTab($activeTab)
            ->tabs([
                Tab::make('Success')
                    ->badge($successCount)
                    ->badgeColor('success')
                    ->schema([
                        Placeholder::make('success_results')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => self::tabPanelHtml(
                                rows: $successRows,
                                tone: 'success',
                                sections: [
                                    'created' => 'Created',
                                    'stock_filled' => 'Starting stock added',
                                ],
                            )),
                    ]),
                Tab::make('Updated')
                    ->badge($updatedCount)
                    ->badgeColor('info')
                    ->schema([
                        Placeholder::make('updated_results')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => self::tabPanelHtml(
                                rows: $updatedRows,
                                tone: 'info',
                                sections: [
                                    'updated' => 'Blank catalog fields filled',
                                ],
                            )),
                    ]),
                Tab::make('Skipped')
                    ->badge($skippedCount)
                    ->badgeColor('warning')
                    ->schema([
                        Placeholder::make('skipped_results')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => self::tabPanelHtml(
                                rows: $skippedRows,
                                tone: 'warning',
                                sections: [
                                    'skipped_has_stock' => 'Skipped — already has stock',
                                    'skipped_existing' => 'Skipped — already in catalog',
                                    'skipped_duplicate' => 'Skipped — duplicate in this file',
                                ],
                            )),
                    ]),
                Tab::make('Invalid')
                    ->badge($invalidCount)
                    ->badgeColor('danger')
                    ->schema([
                        Placeholder::make('invalid_results')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => self::tabPanelHtml(
                                rows: $invalidRows,
                                tone: 'danger',
                                sections: [
                                    'invalid' => 'Invalid rows',
                                ],
                            )),
                    ]),
            ]);

        return $components;
    }

    public static function sampleDownloadResponse(): StreamedResponse
    {
        $slug = self::currentCategorySlug();
        $spreadsheet = ConsumableItemSpreadsheetReader::sampleSpreadsheetForSlug($slug);
        $filename = ConsumableItemSpreadsheetReader::sampleFilenameForSlug($slug);

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    protected static function categoryImportHeadingLabel(): string
    {
        return match (self::currentCategorySlug()) {
            'semi_expendable' => 'semi-expendable items',
            'ppe' => 'PPE items',
            default => 'consumable items',
        };
    }

    protected static function importModalDescription(): string
    {
        return match (self::currentCategorySlug()) {
            'semi_expendable' => 'Upload a spreadsheet to add semi-expendable catalog items or fill blank fields on re-import.',
            'ppe' => 'Upload a spreadsheet to add PPE catalog items or fill blank fields on re-import.',
            default => 'Upload a spreadsheet to add consumable catalog items or fill blank fields on re-import.',
        };
    }

    protected static function importHelpHtml(): string
    {
        $slug = self::currentCategorySlug();

        $required = [
            'Base item',
            'Unit',
            'Quantity — optional; use 0 or leave blank if not recording starting stock',
        ];

        $recommended = [
            'Sub-item',
            'Reorder point',
            'Description',
        ];

        $tips = [
            'Re-import fills blank catalog fields only — it never overwrites values already set or reorder point.',
            'Put sizes like 500ml in Sub-item, not Unit.',
            'Download the sample file for the full column layout and example values.',
        ];

        $createRequired = match ($slug) {
            'semi_expendable' => [
                'Property class — official COA label (see sample file)',
                'UACS object code — e.g. 106-03',
                'Estimated useful life — months; must be greater than 12',
                'Unit cost — required when Quantity ≥ 1; must be below ₱50,000',
            ],
            'ppe' => [
                'Type of PPE — official COA label (see sample file)',
                'UACS object code — e.g. 106-03',
                'Unit cost — required when Quantity ≥ 1; must be at least ₱50,000',
            ],
            default => [
                'Inventory type — official label or a new type you type',
                'Days to consume',
                'Unit cost — optional for starting stock (blank = ₱0)',
            ],
        };

        $html = '<div style="display:flex;flex-direction:column;gap:0.875rem;font-size:0.875rem;line-height:1.5;color:#4b5563;">';
        $html .= self::importHelpSection('Required columns', $required);
        $html .= self::importHelpSection('Optional columns', $recommended);
        $html .= self::importHelpSection(
            'Required for new catalog items',
            $createRequired,
            'Only when the row does not match an existing item in this category. Re-imports can leave these blank if the item already exists.',
        );
        $html .= self::importHelpSection('Import notes', $tips);
        $html .= '</div>';

        return $html;
    }

    /**
     * @param  list<string>  $items
     */
    protected static function importHelpSection(string $title, array $items, ?string $hint = null): string
    {
        $list = '';
        foreach ($items as $item) {
            $list .= '<li style="margin:0.25rem 0;">'.e($item).'</li>';
        }

        $hintHtml = $hint !== null
            ? '<p style="margin:0 0 0.375rem;font-size:0.8125rem;color:#6b7280;">'.e($hint).'</p>'
            : '';

        return '<div>'
            .'<p style="margin:0 0 0.25rem;font-weight:700;color:#111827;">'.e($title).'</p>'
            .$hintHtml
            .'<ul style="margin:0;padding-left:1.25rem;list-style-type:disc;list-style-position:outside;">'.$list.'</ul>'
            .'</div>';
    }

    /**
     * @param  array{
     *     created?: int,
     *     createdNames?: list<string>,
     *     updatedNames?: list<string>,
     *     stockFilled?: list<string>,
     *     skippedHasStock?: list<string>,
     *     skippedExistingNoQty?: list<string>,
     *     skippedInFile?: list<string>,
     *     invalid?: list<array{row: int, name: string, reason: string}>
     * }  $result
     */
    protected static function sendSummaryNotification(array $result): void
    {
        $created = (int) ($result['created'] ?? 0);
        $updated = count($result['updatedNames'] ?? []);
        $stockFilled = count($result['stockFilled'] ?? []);
        $skippedCount = count($result['skippedHasStock'] ?? [])
            + count($result['skippedExistingNoQty'] ?? [])
            + count($result['skippedInFile'] ?? []);
        $invalidCount = count($result['invalid'] ?? []);

        $parts = [];
        if ($created > 0) {
            $parts[] = $created === 1 ? '1 item created' : "{$created} items created";
        }
        if ($updated > 0) {
            $parts[] = $updated === 1 ? '1 item updated' : "{$updated} items updated";
        }
        if ($stockFilled > 0) {
            $parts[] = $stockFilled === 1
                ? 'starting stock added for 1 existing item'
                : "starting stock added for {$stockFilled} existing items";
        }

        $title = $parts !== []
            ? Str::ucfirst(implode('; ', $parts))
            : 'Import finished';

        $detailBits = [];
        if ($skippedCount > 0) {
            $detailBits[] = $skippedCount === 1 ? '1 row skipped' : "{$skippedCount} rows skipped";
        }
        if ($invalidCount > 0) {
            $detailBits[] = $invalidCount === 1 ? '1 invalid row' : "{$invalidCount} invalid rows";
        }

        $notification = Notification::make()->title($title);

        if ($skippedCount > 0 || $invalidCount > 0) {
            $notification
                ->warning()
                ->body($detailBits !== [] ? implode(', ', $detailBits).'.' : null);
        } elseif ($created > 0 || $updated > 0 || $stockFilled > 0) {
            $notification->success();
        } else {
            $notification
                ->warning()
                ->body('The file had no rows to import.');
        }

        $notification
            ->actions([
                Action::make('viewImportResults')
                    ->label('View results')
                    ->button()
                    ->close()
                    ->dispatchSelf('open-consumable-import-results'),
            ])
            ->send();
    }

    protected static function resolveUploadedSpreadsheetPath(mixed $file): ?string
    {
        if (is_array($file)) {
            $file = Arr::first($file);
        }

        if ($file instanceof TemporaryUploadedFile || $file instanceof UploadedFile) {
            $path = $file->getRealPath() ?: $file->getPathname();

            return is_string($path) && is_file($path) ? $path : null;
        }

        if (is_string($file) && is_file($file)) {
            return $file;
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $statuses
     * @return list<array<string, mixed>>
     */
    protected static function rowsForStatuses(array $rows, array $statuses): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => in_array($row['status'] ?? '', $statuses, true),
        ));
    }

    protected static function summaryHtml(
        int $created,
        int $updatedCount,
        int $skippedCount,
        int $invalidCount,
    ): HtmlString {
        $createdLabel = $created === 1 ? '1 created' : "{$created} created";
        $updatedLabel = $updatedCount === 1 ? '1 updated' : "{$updatedCount} updated";
        $skippedLabel = $skippedCount === 1 ? '1 skipped' : "{$skippedCount} skipped";
        $invalidLabel = $invalidCount === 1 ? '1 invalid' : "{$invalidCount} invalid";

        $html = '<div class="space-y-2 text-sm">'
            .'<p class="text-sm leading-6">'
            .self::summaryChipHtml($createdLabel, 'success')
            .' <span class="text-gray-400 px-1">·</span> '
            .self::summaryChipHtml($updatedLabel, 'info')
            .' <span class="text-gray-400 px-1">·</span> '
            .self::summaryChipHtml($skippedLabel, 'warning')
            .' <span class="text-gray-400 px-1">·</span> '
            .self::summaryChipHtml($invalidLabel, 'danger')
            .'</p>'
            .'<p class="text-gray-600 dark:text-gray-300">'
            .'Compare Excel values with what was saved or matched in the catalog. Use the tabs below to review each outcome.'
            .'</p></div>';

        return new HtmlString($html);
    }

    protected static function summaryChipHtml(string $label, string $tone): string
    {
        $classes = match ($tone) {
            'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30',
            'info' => 'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-400/10 dark:text-info-400 dark:ring-info-400/30',
            'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30',
            'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/30',
            default => 'bg-gray-50 text-gray-700 ring-gray-600/10 dark:bg-gray-400/10 dark:text-gray-300 dark:ring-gray-400/20',
        };

        return '<span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset '.$classes.'">'
            .e($label)
            .'</span>';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $sections
     */
    protected static function tabPanelHtml(array $rows, string $tone, array $sections): HtmlString
    {
        $borderClass = match ($tone) {
            'success' => 'border-success-500',
            'info' => 'border-info-500',
            'warning' => 'border-warning-500',
            'danger' => 'border-danger-500',
            default => 'border-gray-300',
        };

        $badgeClass = match ($tone) {
            'success' => 'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400',
            'info' => 'bg-info-50 text-info-700 dark:bg-info-400/10 dark:text-info-400',
            'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400',
            'danger' => 'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400',
            default => 'bg-gray-50 text-gray-700 dark:bg-gray-400/10 dark:text-gray-300',
        };

        $total = count($rows);
        $displayRows = array_slice($rows, 0, self::RESULTS_PREVIEW_LIMIT);
        $hiddenCount = $total - count($displayRows);
        $html = '<div class="owwa-import-results max-h-[60vh] overflow-y-auto space-y-4 text-sm pe-1 border-s-4 ps-3 '.$borderClass.'">';

        if ($total === 0) {
            $html .= '<p class="text-gray-500">No rows in this category.</p></div>';

            return new HtmlString($html);
        }

        $html .= '<p class="text-xs text-gray-500 dark:text-gray-400">'
            .'Showing '.count($displayRows).($hiddenCount > 0 ? ' of '.$total : '').' row'.($total === 1 ? '' : 's')
            .' (numbers continue across subsections below).'
            .($hiddenCount > 0
                ? ' Only the first '.self::RESULTS_PREVIEW_LIMIT.' are listed here so the page stays responsive.'
                : '')
            .'</p>';

        $nextNumber = 1;

        foreach ($sections as $status => $heading) {
            $sectionRows = array_values(array_filter(
                $displayRows,
                fn (array $row): bool => ($row['status'] ?? '') === $status,
            ));

            if ($sectionRows === []) {
                continue;
            }

            $sectionCount = count($sectionRows);
            $html .= '<div>';
            $html .= '<div class="mb-2 flex flex-wrap items-center gap-2">'
                .'<p class="font-medium text-gray-800 dark:text-gray-100">'.e($heading).'</p>'
                .'<span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[11px] font-medium '.$badgeClass.'">'
                .$sectionCount.' of '.$total
                .'</span></div>';
            $html .= self::comparisonTableHtml($sectionRows, $nextNumber);
            $html .= '</div>';

            $nextNumber += $sectionCount;
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected static function comparisonTableHtml(array $rows, int $startNumber = 1): string
    {
        $html = '<div class="overflow-x-auto">'
            .'<table class="owwa-import-results-table w-full border-collapse text-left text-xs sm:text-sm">'
            .'<thead class="text-gray-600 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">'
            .'<tr>'
            .'<th class="px-2 py-2 font-medium w-10 text-center border-r border-gray-200 dark:border-gray-700">#</th>'
            .'<th class="px-2 py-2 font-medium border-r border-gray-200 dark:border-gray-700">Excel</th>'
            .'<th class="px-2 py-2 font-medium">In system</th>'
            .'</tr></thead><tbody>';

        foreach ($rows as $index => $row) {
            $n = $startNumber + $index;
            $excel = is_array($row['excel'] ?? null) ? $row['excel'] : [];
            $actual = is_array($row['actual'] ?? null) ? $row['actual'] : null;
            $reason = filled($row['reason'] ?? null) ? (string) $row['reason'] : null;
            $excelRow = (int) ($row['excel_row'] ?? 0);
            $itemName = filled($excel['item_name'] ?? null) ? (string) $excel['item_name'] : null;

            $fieldRows = [];

            // Item name is Excel reference only when it differs from Base (avoids repeating the same label).
            if ($itemName !== null) {
                $excelBase = (string) ($excel['base'] ?? '');
                $itemNameIsRedundant = ConsumableItemSpreadsheetReader::normalizeNameKey($itemName)
                    === ConsumableItemSpreadsheetReader::normalizeNameKey($excelBase)
                    && ! filled($excel['sub'] ?? null);

                if (! $itemNameIsRedundant) {
                    $fieldRows[] = [
                        'label' => 'Item name',
                        'excel' => $itemName,
                        'system' => $actual['name'] ?? null,
                        'systemLabel' => 'Name',
                        'diff' => $actual !== null && self::valuesDiffer($itemName, $actual['name'] ?? null),
                    ];
                }
            }

            $fieldRows[] = [
                'label' => 'Base',
                'excel' => (string) ($excel['base'] ?? ''),
                'system' => $actual['base'] ?? null,
                'diff' => $actual !== null && self::valuesDiffer($excel['base'] ?? null, $actual['base'] ?? null),
            ];

            $fieldRows[] = [
                'label' => 'Sub-item',
                'excel' => filled($excel['sub'] ?? null) ? (string) $excel['sub'] : '—',
                'system' => $actual === null ? null : (filled($actual['sub'] ?? null) ? (string) $actual['sub'] : '—'),
                'diff' => $actual !== null && self::valuesDiffer($excel['sub'] ?? null, $actual['sub'] ?? null),
            ];

            $fieldRows[] = [
                'label' => 'Unit',
                'excel' => (string) ($excel['unit'] ?? ''),
                'system' => $actual['unit'] ?? null,
                'diff' => $actual !== null && self::valuesDiffer($excel['unit'] ?? null, $actual['unit'] ?? null),
            ];

            $fieldRows[] = [
                'label' => 'Reorder point',
                'excel' => (string) ((int) ($excel['reorder_level'] ?? 0)),
                'system' => $actual !== null ? (string) ((int) ($actual['reorder_level'] ?? 0)) : null,
                'diff' => $actual !== null && (int) ($excel['reorder_level'] ?? 0) !== (int) ($actual['reorder_level'] ?? 0),
            ];

            if (
                filled($excel['inventory_type_label'] ?? null)
                || filled($actual['inventory_type_label'] ?? null)
            ) {
                $fieldRows[] = [
                    'label' => 'Inventory type',
                    'excel' => filled($excel['inventory_type_label'] ?? null)
                        ? (string) $excel['inventory_type_label']
                        : '—',
                    'system' => filled($actual['inventory_type_label'] ?? null)
                        ? (string) $actual['inventory_type_label']
                        : ($actual === null ? null : '—'),
                    'diff' => $actual !== null && self::valuesDiffer(
                        $excel['inventory_type_label'] ?? null,
                        $actual['inventory_type_label'] ?? null,
                    ),
                ];
            }

            if (
                filled($excel['property_class_label'] ?? null)
                || filled($actual['property_class_label'] ?? null)
            ) {
                $fieldRows[] = [
                    'label' => 'Property class',
                    'excel' => filled($excel['property_class_label'] ?? null)
                        ? (string) $excel['property_class_label']
                        : '—',
                    'system' => filled($actual['property_class_label'] ?? null)
                        ? (string) $actual['property_class_label']
                        : ($actual === null ? null : '—'),
                    'diff' => $actual !== null && self::valuesDiffer(
                        $excel['property_class_label'] ?? null,
                        $actual['property_class_label'] ?? null,
                    ),
                ];
            }

            if (
                filled($excel['ppe_type_label'] ?? null)
                || filled($actual['ppe_type_label'] ?? null)
            ) {
                $fieldRows[] = [
                    'label' => 'Type of PPE',
                    'excel' => filled($excel['ppe_type_label'] ?? null)
                        ? (string) $excel['ppe_type_label']
                        : '—',
                    'system' => filled($actual['ppe_type_label'] ?? null)
                        ? (string) $actual['ppe_type_label']
                        : ($actual === null ? null : '—'),
                    'diff' => $actual !== null && self::valuesDiffer(
                        $excel['ppe_type_label'] ?? null,
                        $actual['ppe_type_label'] ?? null,
                    ),
                ];
            }

            if (
                filled($excel['uacs_code'] ?? null)
                || filled($actual['uacs_code'] ?? null)
            ) {
                $fieldRows[] = [
                    'label' => 'UACS',
                    'excel' => filled($excel['uacs_code'] ?? null) ? (string) $excel['uacs_code'] : '—',
                    'system' => filled($actual['uacs_code'] ?? null)
                        ? (string) $actual['uacs_code']
                        : ($actual === null ? null : '—'),
                    'diff' => $actual !== null && self::valuesDiffer(
                        $excel['uacs_code'] ?? null,
                        $actual['uacs_code'] ?? null,
                    ),
                ];
            }

            if (
                filled($excel['estimated_useful_life'] ?? null)
                || filled($actual['estimated_useful_life'] ?? null)
            ) {
                $fieldRows[] = [
                    'label' => 'Estimated useful life',
                    'excel' => filled($excel['estimated_useful_life'] ?? null)
                        ? (string) $excel['estimated_useful_life']
                        : '—',
                    'system' => filled($actual['estimated_useful_life'] ?? null)
                        ? (string) $actual['estimated_useful_life']
                        : ($actual === null ? null : '—'),
                    'diff' => $actual !== null && self::valuesDiffer(
                        $excel['estimated_useful_life'] ?? null,
                        $actual['estimated_useful_life'] ?? null,
                    ),
                ];
            }

            if (
                ($excel['days_to_consume'] ?? null) !== null
                || ($actual['days_to_consume'] ?? null) !== null
            ) {
                $fieldRows[] = [
                    'label' => 'Days to consume',
                    'excel' => ($excel['days_to_consume'] ?? null) === null ? '—' : (string) $excel['days_to_consume'],
                    'system' => ($actual['days_to_consume'] ?? null) === null ? '—' : (string) $actual['days_to_consume'],
                    'diff' => $actual !== null && (int) ($excel['days_to_consume'] ?? 0) !== (int) ($actual['days_to_consume'] ?? 0),
                ];
            }

            if (
                filled($excel['description'] ?? null)
                || filled($actual['description'] ?? null)
            ) {
                $fieldRows[] = [
                    'label' => 'Description',
                    'excel' => filled($excel['description'] ?? null) ? (string) $excel['description'] : '—',
                    'system' => filled($actual['description'] ?? null) ? (string) $actual['description'] : '—',
                    'diff' => $actual !== null && self::valuesDiffer($excel['description'] ?? null, $actual['description'] ?? null),
                ];
            }

            $fieldRows[] = [
                'label' => 'Qty',
                'excel' => $excel['qty'] === null || $excel['qty'] === '' ? '—' : (string) $excel['qty'],
                'system' => null,
                'diff' => false,
            ];

            if (($excel['unit_cost'] ?? null) !== null) {
                $excelCost = self::formatUnitCostDisplay($excel['unit_cost'] ?? null);
                $systemCost = ($actual['unit_cost'] ?? null) !== null
                    ? self::formatUnitCostDisplay($actual['unit_cost'])
                    : '—';
                $fieldRows[] = [
                    'label' => 'Unit cost',
                    'excel' => $excelCost,
                    'system' => $systemCost,
                    'diff' => $actual !== null
                        && ($actual['unit_cost'] ?? null) !== null
                        && self::valuesDiffer($excelCost, $systemCost),
                ];
            }

            if ($excelRow > 0) {
                $fieldRows[] = [
                    'label' => 'Sheet row',
                    'excel' => (string) $excelRow,
                    'system' => $reason,
                    'systemLabel' => null,
                    'diff' => false,
                    'systemMuted' => $reason === null,
                ];
            } elseif ($reason !== null) {
                $fieldRows[] = [
                    'label' => null,
                    'excel' => null,
                    'system' => $reason,
                    'systemLabel' => null,
                    'diff' => false,
                    'systemMuted' => false,
                ];
            }

            $rowCount = count($fieldRows);

            foreach ($fieldRows as $fieldIndex => $field) {
                $isFirst = $fieldIndex === 0;
                $isLast = $fieldIndex === ($rowCount - 1);
                $rowClass = 'align-top text-gray-700 dark:text-gray-200'
                    .($isLast ? ' border-b border-gray-200 dark:border-gray-700' : '');

                $html .= '<tr class="'.$rowClass.'">';

                if ($isFirst) {
                    $html .= '<td class="px-2 py-0.5 tabular-nums text-gray-500 text-center align-top border-r border-gray-200 dark:border-gray-700">'
                        .e((string) $n).'</td>';
                } else {
                    $html .= '<td class="px-2 py-0.5 border-r border-gray-200 dark:border-gray-700"></td>';
                }

                $html .= '<td class="px-2 py-0.5 leading-snug align-top border-r border-gray-200 dark:border-gray-700">'
                    .self::comparisonCellHtml(
                        label: $field['label'] ?? null,
                        value: $field['excel'] ?? null,
                        differs: (bool) ($field['diff'] ?? false),
                    )
                    .'</td><td class="px-2 py-0.5 leading-snug align-top">';

                if (($field['label'] ?? null) === 'Sheet row' || ($field['label'] ?? null) === null) {
                    $systemValue = $field['system'] ?? null;
                    if ($systemValue === null || $systemValue === '') {
                        $html .= '<span class="text-gray-400">—</span>';
                    } else {
                        $tone = ($field['systemMuted'] ?? false) ? 'text-gray-400' : 'text-amber-700 dark:text-amber-300';
                        $html .= '<span class="text-[11px] '.$tone.'">'.e((string) $systemValue).'</span>';
                    }
                } elseif ($actual === null && ($field['label'] ?? null) === 'Base') {
                    $html .= '<span class="text-gray-400">Not saved</span>';
                } elseif ($actual === null) {
                    $html .= '<span class="text-gray-400">—</span>';
                } elseif (($field['label'] ?? null) === 'Qty') {
                    $html .= '<span class="text-gray-400">—</span>';
                } elseif (($field['label'] ?? null) === 'Item name') {
                    $html .= self::comparisonCellHtml(
                        label: $field['systemLabel'] ?? 'Name',
                        value: $field['system'] ?? null,
                        differs: (bool) ($field['diff'] ?? false),
                    );
                } else {
                    $html .= self::comparisonCellHtml(
                        label: $field['label'] ?? null,
                        value: $field['system'] ?? null,
                        differs: (bool) ($field['diff'] ?? false),
                    );
                }

                $html .= '</td></tr>';
            }
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    protected static function comparisonCellHtml(?string $label, ?string $value, bool $differs = false): string
    {
        if ($value === null || $value === '') {
            return '<span class="text-gray-400">—</span>';
        }

        $valueClass = $differs
            ? 'font-medium text-amber-800 dark:text-amber-200'
            : 'text-gray-800 dark:text-gray-100';

        if ($label === null) {
            return '<span class="'.$valueClass.'">'.e($value).'</span>';
        }

        return '<span class="text-gray-400">'.e($label).':</span> '
            .'<span class="'.$valueClass.'">'.e($value).'</span>';
    }

    protected static function formatUnitCostDisplay(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (! is_numeric($value)) {
            return (string) $value;
        }

        return number_format(round((float) $value, 2), 2, '.', '');
    }

    protected static function valuesDiffer(mixed $excel, mixed $actual): bool
    {
        $left = trim((string) ($excel ?? ''));
        $right = trim((string) ($actual ?? ''));

        if ($left === '' && ($right === '' || $right === '—')) {
            return false;
        }

        return mb_strtolower($left) !== mb_strtolower($right);
    }

    protected static function currentCategoryId(): int
    {
        return SyncsActiveItemCategory::resolveCategoryIdFromContext();
    }

    protected static function currentCategorySlug(): string
    {
        $categoryId = self::currentCategoryId();
        if ($categoryId <= 0) {
            return 'consumables';
        }

        $category = ItemCategory::query()->find($categoryId);

        return $category?->getTemplateSlug() ?? 'consumables';
    }

    protected static function isImportableCategory(): bool
    {
        return in_array(self::currentCategorySlug(), ['consumables', 'semi_expendable', 'ppe'], true);
    }

    protected static function isConsumablesCategory(): bool
    {
        return self::currentCategorySlug() === 'consumables';
    }
}
