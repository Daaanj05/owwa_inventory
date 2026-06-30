<?php

namespace App\Filament\Resources\PhysicalCountSessions\Actions;

use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\PhysicalCountSession;
use App\Services\PhysicalCountCompletionService;
use App\Services\PhysicalCountPreloadService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Redirect;

class PhysicalCountSessionActions
{
    public static function scanWithPhoneAction(): Action
    {
        return Action::make('scanWithPhone')
            ->label('Scan with phone')
            ->icon('heroicon-o-camera')
            ->color('primary')
            ->visible(fn (PhysicalCountSession $record): bool => $record->supportsQrScanning() && ! $record->isComplete())
            ->url(fn (PhysicalCountSession $record): string => PhysicalCountSessionResource::getUrl('scan', ['record' => $record]));
    }

    public static function preloadExpectedAssetsAction(?callable $afterSuccess = null): Action
    {
        return Action::make('preloadExpectedAssets')
            ->label('Load expected assets')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->visible(fn (PhysicalCountSession $record): bool => $record->supportsQrScanning() && ! $record->hasBookListLoaded())
            ->requiresConfirmation()
            ->modalHeading('Load expected assets from custody records?')
            ->modalDescription('Loads the book list from in-stock inventory units for this office. Unscanned units appear as shortages. Use this on desktop after mobile scanning.')
            ->action(function (PhysicalCountSession $record, Action $action) use ($afterSuccess): void {
                $result = app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($record);

                Notification::make()
                    ->title('Expected assets loaded')
                    ->body("Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}. Book list loaded — shortages and overages are now visible.")
                    ->success()
                    ->send();

                if ($afterSuccess !== null) {
                    $afterSuccess($record, $action);

                    return;
                }

                $action->halt();
            });
    }

    public static function markCompleteAction(): Action
    {
        return Action::make('markComplete')
            ->label('Mark complete')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (PhysicalCountSession $record): bool => $record->supportsQrScanning() && ! $record->isComplete())
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

                Notification::make()
                    ->title('Session marked complete')
                    ->success()
                    ->send();

                $action->halt();
            });
    }

    public static function printQrLabelsAction(): Action
    {
        return Action::make('printQrLabels')
            ->label('Print QR labels')
            ->icon('heroicon-o-qr-code')
            ->visible(fn (PhysicalCountSession $record): bool => $record->supportsQrScanning())
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
            ->action(fn (PhysicalCountSession $record) => Redirect::away(route('owwa.export.physical-count', $record)));
    }

    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_STANDARD);
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    public static function modalFooterActions(): array
    {
        return [
            self::scanWithPhoneAction(),
            self::preloadExpectedAssetsAction(),
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
