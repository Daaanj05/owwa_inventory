<?php

namespace App\Support;

use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

class AboutModalAction
{
    public static function make(): Action
    {
        return Action::make('about')
            ->label('About')
            ->icon(Heroicon::OutlinedInformationCircle)
            ->modalHeading('About this system')
            ->modalWidth(Width::ExtraLarge)
            ->modalContent(fn (): View => view('filament.pages.partials.about-content', [
                'about' => SystemCredits::about(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->extraModalWindowAttributes(['class' => 'owwa-about-modal']);
    }
}
