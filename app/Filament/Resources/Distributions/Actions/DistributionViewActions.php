<?php

namespace App\Filament\Resources\Distributions\Actions;

use App\Models\Distribution;
use App\Models\User;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Livewire\Component as LivewireComponent;

class DistributionViewActions
{
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
            ->visible(fn (): bool => Auth::user() instanceof User && Auth::user()->isSupplyCustodian())
            ->action(function (Distribution $record, Action $action) use ($asPdf): void {
                $url = route('owwa.export.distribution', $record).($asPdf ? '?format=pdf' : '');
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
