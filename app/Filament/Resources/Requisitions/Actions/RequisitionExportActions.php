<?php

namespace App\Filament\Resources\Requisitions\Actions;

use App\Models\Requisition;
use App\Models\User;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Livewire\Component as LivewireComponent;

class RequisitionExportActions
{
    public static function exportRisAction(): Action
    {
        return self::exportAction('exportRis', 'Export RIS Excel', false);
    }

    public static function exportRisPdfAction(): Action
    {
        return self::exportAction('exportRisPdf', 'Export RIS PDF', true);
    }

    protected static function exportAction(string $name, string $label, bool $asPdf): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($asPdf ? 'heroicon-o-document-text' : 'heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn (Requisition $record): bool => self::userCanExportRis() && $record->canExportRis())
            ->action(function (Requisition $record, Action $action) use ($asPdf): void {
                $url = route('owwa.export.requisition', $record).($asPdf ? '?format=pdf' : '');
                $livewire = $action->getLivewire();
                OwwaExportBusyDispatcher::start(
                    $livewire instanceof LivewireComponent ? $livewire : null,
                    $url,
                    $asPdf ? 'Preparing PDF export…' : 'Preparing Excel export…',
                    $asPdf ? 'Building your OWWA PDF…' : 'Building your OWWA form…',
                );
            });
    }

    public static function userCanExportRis(?User $user = null): bool
    {
        $user ??= Auth::user();

        return $user instanceof User
            && $user->isSupplyCustodian();
    }
}
