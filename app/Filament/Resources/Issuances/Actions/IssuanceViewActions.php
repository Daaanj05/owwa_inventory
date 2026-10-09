<?php

namespace App\Filament\Resources\Issuances\Actions;

use App\Filament\Resources\Issuances\IssuanceResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Issuance;
use App\Models\User;
use App\Services\UsefulLifeExtensionService;
use App\Support\OwwaExportBusyDispatcher;
use App\Support\SemiExpendableUsefulLife;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Livewire\Component as LivewireComponent;

class IssuanceViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(IssuanceResource::class, OwwaFormModalDefaults::WIDTH_WIDE);
    }

    public static function exportOwwaAction(): Action
    {
        return Action::make('exportOwwa')
            ->label('Export Excel')
            ->icon('heroicon-o-document-arrow-down')
            ->action(function (Issuance $record, Action $action): void {
                self::startExport($record, $action, false);
            });
    }

    public static function exportPdfAction(): Action
    {
        return Action::make('exportOwwaPdf')
            ->label('Export PDF')
            ->icon('heroicon-o-document-text')
            ->action(function (Issuance $record, Action $action): void {
                self::startExport($record, $action, true);
            });
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    public static function exportActions(): array
    {
        return [
            ActionGroup::make([
                self::exportOwwaAction(),
                self::exportPdfAction(),
            ])
                ->label('Export')
                ->icon('heroicon-m-document-arrow-down')
                ->color('gray')
                ->button(),
        ];
    }

    public static function extendUsefulLifeAction(): Action
    {
        return Action::make('extendUsefulLife')
            ->label('Extend useful life')
            ->icon('heroicon-o-clock')
            ->visible(function (Issuance $record): bool {
                $user = Filament::auth()->user();
                if (! $user instanceof User || ! $user->isSupplyCustodian()) {
                    return false;
                }

                $record->loadMissing('item.category');
                if ($record->item?->category?->getTemplateSlug() !== 'semi_expendable') {
                    return false;
                }

                if ($record->useful_life_condition !== Issuance::USEFUL_LIFE_STILL_USABLE) {
                    return false;
                }

                $status = SemiExpendableUsefulLife::statusForIssuance($record);

                return in_array($status, [
                    SemiExpendableUsefulLife::STATUS_NEARING,
                    SemiExpendableUsefulLife::STATUS_EXPIRED,
                ], true);
            })
            ->schema([
                TextInput::make('months')
                    ->label('New estimated useful life (months)')
                    ->numeric()
                    ->required()
                    ->minValue(SemiExpendableUsefulLife::minMonths() + 1)
                    ->helperText(SemiExpendableUsefulLife::labelSummary()),
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (Issuance $record, array $data): void {
                $approver = Filament::auth()->user();
                if (! $approver instanceof User) {
                    return;
                }

                $eul = SemiExpendableUsefulLife::storeFromMonths((int) $data['months']);
                app(UsefulLifeExtensionService::class)->extend(
                    $record,
                    (string) $eul,
                    (string) ($data['reason'] ?? ''),
                    $approver,
                );

                Notification::make()
                    ->title('Useful life extended')
                    ->success()
                    ->send();
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
                    && $record->batchLines()->count() === 1
                    && filled($record->property_number);
            })
            ->url(fn (Issuance $record): string => route('owwa.qr-labels.issuance', $record))
            ->openUrlInNewTab();
    }

    protected static function startExport(Issuance $record, Action $action, bool $asPdf): void
    {
        $record->loadMissing('item.category');
        $isConsumableRsmiPdf = $asPdf
            && $record->item?->category?->getTemplateSlug() === 'consumables';

        $url = $isConsumableRsmiPdf
            ? route('owwa.export.issuance.rsmi-fast-pdf', $record)
            : route('owwa.export.issuance', $record).($asPdf ? '?format=pdf' : '');

        $livewire = $action->getLivewire();
        OwwaExportBusyDispatcher::start(
            $livewire instanceof LivewireComponent ? $livewire : null,
            $url,
            $asPdf ? 'Preparing PDF export…' : 'Preparing Excel export…',
            $asPdf ? 'Building your OWWA PDF…' : 'Building your OWWA form…',
            $asPdf ? 300000 : 120000,
        );
    }
}
