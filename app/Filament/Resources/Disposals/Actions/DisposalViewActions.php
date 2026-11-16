<?php

namespace App\Filament\Resources\Disposals\Actions;

use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Disposal;
use App\Models\User;
use App\Services\DisposalStockValidator;
use App\Services\DisposalWorkflowService;
use App\Services\OwwaTemplateExportService;
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

class DisposalViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(DisposalResource::class, OwwaFormModalDefaults::WIDTH_STANDARD)
            ->visible(fn (Disposal $record): bool => $record->isEditable())
            ->mutateFormDataUsing(function (array $data, Disposal $record): array {
                app(DisposalStockValidator::class)->validateForUpdate($data, $record);

                return $data;
            });
    }

    public static function markAsApprovedAction(): Action
    {
        return Action::make('markAsApproved')
            ->label('Mark as approved')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Mark disposal as approved?')
            ->modalDescription('This applies the stock write-off and locks the record from further edits.')
            ->modalSubmitActionLabel('Mark as approved')
            ->visible(fn (Disposal $record): bool => ! $record->isConfirmed()
                && ($user = Filament::auth()->user()) instanceof User
                && $user->isSupplyCustodian())
            ->disabled(fn (Disposal $record): bool => $record->exported_at === null)
            ->tooltip(fn (Disposal $record): ?string => $record->exported_at === null
                ? 'Export the disposal form before marking as approved.'
                : null)
            ->action(function (Disposal $record): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                try {
                    if ($record->isFromPropertyReturn()) {
                        app(PropertyReturnDecisionService::class)->markDisposalApproved($record, $user);
                    } else {
                        if ($record->exported_at === null) {
                            throw new InvalidArgumentException('Export the disposal form before marking as approved.');
                        }
                        app(DisposalWorkflowService::class)->confirm($record);
                    }

                    Notification::make()
                        ->title('Disposal marked as approved')
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

    /** @deprecated Use markAsApprovedAction() */
    public static function confirmAction(): Action
    {
        return self::markAsApprovedAction();
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
            ->action(function (Disposal $record, Action $action) use ($asPdf): void {
                $form = app(OwwaTemplateExportService::class)->resolveDisposalFormSlug($record);
                $query = ['form' => $form];
                if ($asPdf) {
                    $query['format'] = 'pdf';
                }
                $url = route('owwa.export.disposal', $record).'?'.http_build_query($query);

                $livewire = $action->getLivewire();
                OwwaExportBusyDispatcher::start(
                    $livewire instanceof LivewireComponent ? $livewire : null,
                    $url,
                    $asPdf ? 'Preparing PDF export…' : 'Preparing Excel export…',
                    $asPdf ? 'Building your OWWA PDF…' : 'Building your OWWA form…',
                );
            });
    }
}
