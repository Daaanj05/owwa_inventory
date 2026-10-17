<?php

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication as BaseSetUpRequiredMultiFactorAuthentication;
use Filament\Facades\Filament;

class SetUpRequiredMultiFactorAuthentication extends BaseSetUpRequiredMultiFactorAuthentication
{
    protected string $view = 'filament.pages.auth.set-up-required-mfa';

    public function hasLogo(): bool
    {
        return false;
    }

    /**
     * @return array<Action>
     */
    public function getFooterActions(): array
    {
        return [
            $this->getContinueAction(),
            $this->getCancelAction(),
        ];
    }

    public function getCancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->color('gray')
            ->action(fn (): mixed => $this->cancelSetup());
    }

    public function cancelSetup(): mixed
    {
        Filament::auth()->logout();

        session()->invalidate();
        session()->regenerateToken();

        return $this->redirect(Filament::getLoginUrl() ?? url('/login'));
    }
}
