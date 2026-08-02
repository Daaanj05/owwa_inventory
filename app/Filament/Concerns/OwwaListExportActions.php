<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Js;
use Livewire\Component as LivewireComponent;

/**
 * Shared OWWA Excel export from inventory task list pages (header action + bulk action).
 */
final class OwwaListExportActions
{
    public static function headerAction(
        string $name,
        string $routeName,
        string $exportSource = 'schema_header',
        ?string $label = null,
        ?string $selectionHint = null,
    ): Action {
        $isRisExport = $routeName === 'owwa.export.bulk.requisitions';

        return Action::make($name)
            ->label($label ?? 'Export Report')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->modal(fn ($livewire): bool => $isRisExport || self::hasMoreThanOneSelectedRecord($livewire))
            ->modalHeading(fn ($livewire): ?string => ($isRisExport || self::hasMoreThanOneSelectedRecord($livewire))
                ? 'Export options'
                : null)
            ->form(function ($livewire) use ($isRisExport, $selectionHint): array {
                if (! $isRisExport && ! self::hasMoreThanOneSelectedRecord($livewire)) {
                    return [];
                }

                $showLayout = self::hasMoreThanOneSelectedRecord($livewire);

                return array_values(array_filter([
                    filled($selectionHint)
                        ? \Filament\Forms\Components\Placeholder::make('export_hint')
                            ->label('')
                            ->content($selectionHint)
                            ->columnSpanFull()
                        : null,
                    Select::make('export_format')
                        ->label('Format')
                        ->options([
                            'xlsx' => 'Excel',
                            'pdf' => 'PDF',
                        ])
                        ->default('xlsx')
                        ->required()
                        ->visible($isRisExport),
                    Select::make('export_layout')
                        ->label('When more than one row is selected')
                        ->options($isRisExport
                            ? [
                                'workbook' => 'One Excel file (separate sheet per RIS)',
                                'individual' => 'Separate file per RIS (page with download links)',
                            ]
                            : [
                                'workbook' => 'One Excel file (all rows on one form)',
                                'individual' => 'Separate Excel per transaction (page with download links)',
                            ])
                        ->default('workbook')
                        ->required()
                        ->visible($showLayout),
                ]));
            })
            ->action(function (array $data, Action $action) use ($routeName, $exportSource): void {
                $livewire = $action->getLivewire();
                if (! $livewire instanceof HasTable) {
                    Notification::make()
                        ->title('Export could not be started.')
                        ->danger()
                        ->send();

                    return;
                }

                self::runExport(
                    $livewire,
                    $routeName,
                    $exportSource,
                    $action->getName(),
                    (string) ($data['export_layout'] ?? 'workbook'),
                    (string) ($data['export_format'] ?? 'xlsx'),
                );
            });
    }

    /**
     * Use next to Create on list {@see \Filament\Schemas\Components\Actions}: routes the click through the table
     * Alpine helper so {@see \Filament\Tables\Concerns\HasBulkActions::$selectedTableRecords} is synced before Livewire runs.
     */
    public static function schemaHeaderExportAction(
        string $name,
        string $routeName,
        ?string $label = null,
        ?string $selectionHint = null,
    ): Action {
        return self::headerAction($name, $routeName, 'schema_header', $label, $selectionHint)
            ->livewireClickHandlerEnabled(false)
            ->actionJs(function (Action $action): string {
                $actionName = str_replace(['\\', "'"], ['\\\\', "\\'"], $action->getName());

                $livewire = $action->getLivewire();
                $wireId = $livewire instanceof LivewireComponent ? $livewire->getId() : '';
                $wireIdJs = str_replace(['\\', "'"], ['\\\\', "\\'"], $wireId);

                $context = $action->getContext();
                $mountSuffix = '';
                if (count($context) > 0) {
                    $mountSuffix = ', {}, '.Js::from($context)->toHtml();
                }

                // Scope .fi-ta to this Livewire root; pass Action::getContext() so schema actions resolve (not resolveTableAction).
                // x-on:click is emitted inside a double-quoted HTML attribute — avoid '"' inside this expression.
                return '(function(){'
                    .'const lw=window.Livewire&&\''.$wireIdJs.'\'?window.Livewire.find(\''.$wireIdJs.'\'):null;'
                    .'const r=lw&&lw.$el&&lw.$el.querySelector?lw.$el.querySelector(\'.fi-ta\'):document.querySelector(\'.fi-ta\');'
                    .'if(!r||!window.Alpine)return;'
                    .'const alpine=window.Alpine;'
                    .'const d=typeof alpine.$data===\'function\'?alpine.$data(r):null;'
                    .'if(!d||typeof d.mountAction!==\'function\')return;'
                    .'d.mountAction(\''.$actionName.'\''.$mountSuffix.');'
                    .'})()';
            });
    }

    public static function bulkAction(string $routeName): BulkAction
    {
        $isRisExport = $routeName === 'owwa.export.bulk.requisitions';

        return BulkAction::make('owwa_export_selected')
            ->label('Export report (Excel)')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->modal(fn ($livewire): bool => $isRisExport || self::hasMoreThanOneSelectedRecord($livewire))
            ->modalHeading(fn ($livewire): ?string => ($isRisExport || self::hasMoreThanOneSelectedRecord($livewire))
                ? 'Export options'
                : null)
            ->form(function ($livewire) use ($isRisExport): array {
                if (! $isRisExport && ! self::hasMoreThanOneSelectedRecord($livewire)) {
                    return [];
                }

                $showLayout = self::hasMoreThanOneSelectedRecord($livewire);

                return array_values(array_filter([
                    Select::make('export_format')
                        ->label('Format')
                        ->options([
                            'xlsx' => 'Excel',
                            'pdf' => 'PDF',
                        ])
                        ->default('xlsx')
                        ->required()
                        ->visible($isRisExport),
                    Select::make('export_layout')
                        ->label('When more than one row is selected')
                        ->options($isRisExport
                            ? [
                                'workbook' => 'One Excel file (separate sheet per RIS)',
                                'individual' => 'Separate file per RIS (page with download links)',
                            ]
                            : [
                                'workbook' => 'One Excel file (all rows on one form)',
                                'individual' => 'Separate Excel per transaction (page with download links)',
                            ])
                        ->default('workbook')
                        ->required()
                        ->visible($showLayout),
                ]));
            })
            ->action(function (array $data, Action $action) use ($routeName): void {
                $livewire = $action->getLivewire();
                if (! $livewire instanceof HasTable) {
                    Notification::make()
                        ->title('Export could not be started.')
                        ->danger()
                        ->send();

                    return;
                }

                self::runExport(
                    $livewire,
                    $routeName,
                    'bulk',
                    $action->getName(),
                    (string) ($data['export_layout'] ?? 'workbook'),
                    (string) ($data['export_format'] ?? 'xlsx'),
                );
            });
    }

    public static function runExport(
        HasTable $livewire,
        string $routeName,
        string $source = 'unknown',
        ?string $actionName = null,
        string $exportLayout = 'workbook',
        string $exportFormat = 'xlsx',
    ): void {
        $baseContext = [
            'owwa_export' => true,
            'route' => $routeName,
            'source' => $source,
            'action_name' => $actionName,
            'livewire_class' => $livewire::class,
        ];

        if (! $livewire instanceof LivewireComponent) {
            Log::warning('owwa_export: livewire is not a Livewire Component', $baseContext);
            Notification::make()
                ->title('Export could not be started.')
                ->danger()
                ->send();

            return;
        }

        $selectionSnapshot = self::selectionDebugSnapshot($livewire);
        Log::info('owwa_export: before getSelectedTableRecords', $baseContext + $selectionSnapshot);

        $selected = $livewire->getSelectedTableRecords(shouldFetchSelectedRecords: true);

        $resolvedContext = $baseContext + [
            'resolved_class' => $selected::class,
            'resolved_count' => $selected->count(),
        ];

        Log::info('owwa_export: after getSelectedTableRecords', $resolvedContext);

        if ($selected->isEmpty()) {
            Log::warning('owwa_export: empty selection after getSelectedTableRecords', $resolvedContext + $selectionSnapshot);
            Notification::make()
                ->title('Please select a transaction before exporting.')
                ->warning()
                ->send();

            return;
        }

        $ids = $selected instanceof EloquentCollection
            ? array_values(array_map(intval(...), $selected->modelKeys()))
            : $selected
                ->map(static function (mixed $item): int {
                    if (is_object($item) && method_exists($item, 'getKey')) {
                        return (int) $item->getKey();
                    }

                    return (int) $item;
                })
                ->unique()
                ->values()
                ->all();

        if ($ids === []) {
            Log::warning('owwa_export: ids empty after mapping', $resolvedContext + ['mapped_ids' => $ids]);
            Notification::make()
                ->title('Please select a transaction before exporting.')
                ->warning()
                ->send();

            return;
        }

        $query = ['ids' => $ids];
        if ($exportLayout === 'individual' || ($exportFormat === 'pdf' && count($ids) > 1)) {
            $query['export_layout'] = 'individual';
        }
        if ($exportFormat === 'pdf') {
            $query['format'] = 'pdf';
        }
        $query['back_url'] = url()->previous();

        $redirectUrl = route($routeName, $query);
        Log::info('owwa_export: redirecting', $resolvedContext + [
            'ids' => $ids,
            'ids_count' => count($ids),
            'export_layout' => $exportLayout,
            'export_format' => $exportFormat,
            'redirect_url' => $redirectUrl,
        ]);

        $isIndividual = ($query['export_layout'] ?? null) === 'individual';

        if ($isIndividual) {
            $livewire->redirect($redirectUrl);

            return;
        }

        \App\Support\OwwaExportBusyDispatcher::start(
            $livewire,
            $redirectUrl,
            $exportFormat === 'pdf' ? 'Preparing PDF export…' : 'Preparing Excel export…',
            $exportFormat === 'pdf'
                ? 'Building your OWWA PDF…'
                : 'Building your OWWA workbook. Large selections can take a little while.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function selectionDebugSnapshot(HasTable $livewire): array
    {
        $out = [];

        $raw = data_get($livewire, 'selectedTableRecords');
        $out['selected_table_records'] = is_array($raw) ? $raw : null;
        $out['selected_table_records_count'] = is_array($raw) ? count($raw) : null;

        $out['is_tracking_deselected'] = (bool) data_get($livewire, 'isTrackingDeselectedTableRecords', false);

        $d = data_get($livewire, 'deselectedTableRecords');
        $out['deselected_table_records_count'] = is_array($d) ? count($d) : null;

        $out['active_tab'] = data_get($livewire, 'activeTab');

        try {
            $query = $livewire->getTable()->getQuery();
            $out['table_query_model'] = $query?->getModel()::class;
            if ($query !== null && is_array($out['selected_table_records'] ?? null) && $out['selected_table_records'] !== []) {
                $out['where_key_match_count'] = (clone $query)->whereKey($out['selected_table_records'])->count();
            }
        } catch (\Throwable $e) {
            $out['table_query_error'] = $e->getMessage();
        }

        return $out;
    }

    private static function hasMoreThanOneSelectedRecord(mixed $livewire): bool
    {
        if (! $livewire instanceof HasTable) {
            return false;
        }

        $raw = data_get($livewire, 'selectedTableRecords');

        return is_array($raw) && count($raw) > 1;
    }
}
