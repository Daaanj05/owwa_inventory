<?php

namespace App\Filament\Resources\Transfers\Actions;

use App\Filament\Resources\Transfers\TransferResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Transfer;
use App\Models\User;
use App\Services\PropertyReturnDecisionService;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use InvalidArgumentException;
use Livewire\Component as LivewireComponent;
use RuntimeException;
use Throwable;

class TransferViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(TransferResource::class, OwwaFormModalDefaults::WIDTH_STANDARD)
            ->visible(fn (Transfer $record): bool => $record->isEditable());
    }

    public static function markAsApprovedAction(): Action
    {
        return Action::make('markAsApproved')
            ->label('Mark as approved')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Mark transfer as approved?')
            ->modalDescription('This posts stock or custody changes and locks the transfer from further edits.')
            ->modalSubmitActionLabel('Mark as approved')
            ->visible(fn (Transfer $record): bool => ! $record->isApproved()
                && ($user = Filament::auth()->user()) instanceof User
                && $user->isSupplyCustodian())
            ->disabled(fn (Transfer $record): bool => $record->exported_at === null)
            ->tooltip(fn (Transfer $record): ?string => $record->exported_at === null
                ? 'Export the PTR before marking as approved.'
                : null)
            ->action(function (Transfer $record): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                try {
                    app(PropertyReturnDecisionService::class)->markTransferApproved($record, $user);
                    Notification::make()
                        ->title('Transfer marked as approved')
                        ->success()
                        ->send();
                } catch (InvalidArgumentException|RuntimeException|Throwable $exception) {
                    Notification::make()
                        ->title('Could not mark as approved')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function exportOwwaAction(): Action
    {
        return self::exportAction('exportOwwa', 'Export Excel', false);
    }

    public static function exportPdfAction(): Action
    {
        return self::exportAction('exportOwwaPdf', 'Export PDF', true);
    }

    protected static function exportAction(string $name, string $label, bool $asPdf): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($asPdf ? 'heroicon-o-document-text' : 'heroicon-o-document-arrow-down')
            ->action(function (Transfer $record, Action $action) use ($asPdf): void {
                $url = route('owwa.export.transfer', $record).($asPdf ? '?format=pdf' : '');

                $livewire = $action->getLivewire();
                OwwaExportBusyDispatcher::start(
                    $livewire instanceof LivewireComponent ? $livewire : null,
                    $url,
                    $asPdf ? 'Preparing PDF export…' : 'Preparing Excel export…',
                    $asPdf ? 'Building your OWWA PDF…' : 'Building your OWWA form…',
                );
            });
    }

    public static function printViewAction(): Action
    {
        return Action::make('printView')
            ->label('Print Preview')
            ->icon('heroicon-o-printer')
            ->url(fn (Transfer $record): string => route('owwa.export.transfer', $record).'?format=pdf')
            ->openUrlInNewTab()
            ->visible(fn (Transfer $record): bool => in_array($record->item?->category?->getTemplateSlug(), ['ppe', 'semi_expendable'], true));
    }
}
