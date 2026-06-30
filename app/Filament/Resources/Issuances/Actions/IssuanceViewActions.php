<?php

namespace App\Filament\Resources\Issuances\Actions;

use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Issuance;
use App\Models\User;
use App\Services\OwwaTemplateExportService;
use App\Services\UsefulLifeExtensionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Redirect;

class IssuanceViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_WIDE);
    }

    public static function exportOwwaAction(): Action
    {
        return Action::make('exportOwwa')
            ->label('Export OWWA Form')
            ->icon('heroicon-o-document-arrow-down')
            ->requiresConfirmation(fn (Issuance $record): bool => self::signatoriesIncomplete($record))
            ->modalHeading('Export without signatories?')
            ->modalDescription('Custodian / issued-by name is blank. Edit this issuance to add signatory names for the OWWA export, or continue with empty signature blocks on the form.')
            ->modalSubmitActionLabel('Export anyway')
            ->form([
                Select::make('form')
                    ->label('OWWA form')
                    ->options(fn (Issuance $record): array => app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('issuance', $record->item?->category))
                    ->default(function (Issuance $record): string {
                        $opts = app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('issuance', $record->item?->category);

                        return array_key_first($opts) ?? '';
                    })
                    ->helperText('The form is auto-selected based on the item category. Change only if needed.'),
            ])
            ->action(function (Issuance $record, array $data) {
                $url = route('owwa.export.issuance', $record);
                if (! empty($data['form'])) {
                    $url .= '?form='.urlencode($data['form']);
                }

                return Redirect::away($url);
            });
    }

    public static function printQrLabelAction(): Action
    {
        return Action::make('printQrLabel')
            ->label('Print QR label')
            ->icon('heroicon-o-qr-code')
            ->visible(function (Issuance $record): bool {
                $slug = $record->item?->category?->getTemplateSlug();

                return in_array($slug, ['ppe', 'semi_expendable'], true)
                    && filled($record->property_number);
            })
            ->url(fn (Issuance $record): string => route('owwa.qr-labels.issuance', $record))
            ->openUrlInNewTab();
    }

    public static function printViewAction(): Action
    {
        return Action::make('printView')
            ->label('Print Preview')
            ->icon('heroicon-o-printer')
            ->url(function (Issuance $record): string {
                $slug = $record->item?->category?->getTemplateSlug();
                $form = $slug === 'ppe' ? 'par' : ($slug === 'semi_expendable' ? 'ics' : '');
                $url = route('owwa.print.issuance', $record);

                return $form !== '' ? $url.'?form='.$form : $url;
            })
            ->openUrlInNewTab();
    }

    public static function extendUsefulLifeAction(): Action
    {
        return Action::make('extendUsefulLife')
            ->label('Extend useful life')
            ->icon('heroicon-o-arrow-path')
            ->visible(function (Issuance $record): bool {
                $user = Filament::auth()->user();

                return $user instanceof User
                    && $user->isSupplyCustodian()
                    && $record->item?->category?->getTemplateSlug() === 'semi_expendable';
            })
            ->form([
                TextInput::make('new_eul')
                    ->label('New estimated useful life')
                    ->placeholder('e.g. 3 yrs')
                    ->required(),
                Textarea::make('reason')
                    ->label('Justification')
                    ->rows(3)
                    ->required(),
            ])
            ->action(function (Issuance $record, array $data): void {
                $user = Filament::auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                app(UsefulLifeExtensionService::class)->extend(
                    $record,
                    (string) $data['new_eul'],
                    (string) $data['reason'],
                    $user,
                );

                Notification::make()
                    ->title('Useful life extended')
                    ->success()
                    ->send();
            });
    }

    protected static function signatoriesIncomplete(Issuance $record): bool
    {
        return blank($record->custodian_printed_name);
    }
}
