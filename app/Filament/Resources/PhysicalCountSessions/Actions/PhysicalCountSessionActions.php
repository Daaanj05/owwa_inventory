<?php

namespace App\Filament\Resources\PhysicalCountSessions\Actions;

use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\PhysicalCountSession;
use App\Services\PhysicalCountCompletionService;
use App\Services\PhysicalCountPreloadService;
use App\Support\OwwaExportBusyDispatcher;
use App\Support\PhysicalCountPropertyClassResolver;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Livewire\Component as LivewireComponent;

class PhysicalCountSessionActions
{
    public static function scanWithPhoneAction(): Action
    {
        return Action::make('scanWithPhone')
            ->label('Scan with phone')
            ->icon('heroicon-o-camera')
            ->color('primary')
            ->visible(fn (PhysicalCountSession $record): bool => $record->supportsQrScanning() && ! $record->isComplete() && ! $record->isArchived())
            ->url(fn (PhysicalCountSession $record): string => PhysicalCountSessionResource::getUrl('scan', ['record' => $record]));
    }

    public static function preloadExpectedAssetsAction(?callable $afterSuccess = null): Action
    {
        return Action::make('preloadExpectedAssets')
            ->label('Load expected assets')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->visible(fn (PhysicalCountSession $record): bool => $record->supportsUnitQrScanning() && ! $record->hasBookListLoaded() && ! $record->isArchived())
            ->requiresConfirmation()
            ->modalHeading('Load expected assets from custody records?')
            ->modalDescription('Loads all property tags accountable to the regional office — warehouse stock and items issued for use. Excludes assets at satellite offices. Unscanned units appear as shortages.')
            ->action(function (PhysicalCountSession $record, Action $action) use ($afterSuccess): void {
                $result = app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($record);

                $record->refresh()->load(['lines.item']);

                Notification::make()
                    ->title('Expected assets loaded')
                    ->body("Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}. Book list loaded — shortages and overages are now visible.")
                    ->success()
                    ->send();

                if ($afterSuccess !== null) {
                    $afterSuccess($record, $action);
                }
            });
    }

    public static function preloadStockLinesAction(?callable $afterSuccess = null): Action
    {
        return Action::make('preloadStockLines')
            ->label('Load stock lines')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->visible(fn (PhysicalCountSession $record): bool => $record->supportsStockQrScanning() && ! $record->hasBookListLoaded() && ! $record->isArchived())
            ->requiresConfirmation()
            ->modalHeading('Load stock lines from Stock Card balances?')
            ->modalDescription('Loads consumable items with stock activity for this office and inventory type. Counted quantities stay at zero until you enter them or scan stock QR labels.')
            ->action(function (PhysicalCountSession $record, Action $action) use ($afterSuccess): void {
                $result = app(PhysicalCountPreloadService::class)->preloadFromStockBalances($record);

                $record->refresh()->load(['lines.item']);

                Notification::make()
                    ->title('Stock lines loaded')
                    ->body("Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}.")
                    ->success()
                    ->send();

                if ($afterSuccess !== null) {
                    $afterSuccess($record, $action);
                }
            });
    }

    public static function markCompleteAction(): Action
    {
        return Action::make('markComplete')
            ->label('Mark complete')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (PhysicalCountSession $record): bool => $record->supportsQrScanning() && ! $record->isComplete() && ! $record->isArchived())
            ->requiresConfirmation()
            ->action(function (PhysicalCountSession $record, Action $action): void {
                try {
                    app(PhysicalCountCompletionService::class)->markComplete($record);
                } catch (\Illuminate\Validation\ValidationException $exception) {
                    Notification::make()
                        ->title('Cannot mark complete')
                        ->body(collect($exception->errors())->flatten()->first() ?? 'Missing required fields.')
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                $record->refresh()->load(['lines.item']);

                Notification::make()
                    ->title('Session marked complete')
                    ->success()
                    ->send();
            });
    }

    public static function printQrLabelsAction(): Action
    {
        return Action::make('printQrLabels')
            ->label('Print QR labels')
            ->icon('heroicon-o-qr-code')
            ->visible(fn (PhysicalCountSession $record): bool => $record->supportsUnitQrScanning())
            ->url(fn (PhysicalCountSession $record): string => route('owwa.qr-labels.physical-count', $record))
            ->openUrlInNewTab();
    }

    public static function exportOwwaAction(): Action
    {
        return Action::make('exportOwwa')
            ->label('Export OWWA form')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn (PhysicalCountSession $record): bool => $record->isComplete())
            ->action(function (PhysicalCountSession $record, Action $action): void {
                $livewire = $action->getLivewire();
                OwwaExportBusyDispatcher::start(
                    $livewire instanceof LivewireComponent ? $livewire : null,
                    route('owwa.export.physical-count', $record),
                    'Preparing Excel export…',
                    'Building your OWWA form…',
                );
            });
    }

    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(PhysicalCountSessionResource::class, OwwaFormModalDefaults::WIDTH_STANDARD)
            ->visible(fn (PhysicalCountSession $record): bool => ! $record->isArchived())
            ->after(function (PhysicalCountSession $record): void {
                PhysicalCountPropertyClassResolver::syncSession($record);
            });
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    public static function modalFooterActions(): array
    {
        return [
            self::scanWithPhoneAction(),
            self::preloadExpectedAssetsAction(),
            self::preloadStockLinesAction(),
            self::markCompleteAction(),
            ActionGroup::make([
                self::editAction(),
                self::printQrLabelsAction(),
                self::exportOwwaAction(),
            ])
                ->label('More')
                ->icon('heroicon-m-ellipsis-horizontal')
                ->color('gray'),
        ];
    }
}
