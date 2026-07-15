<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\Auth\AccountSettings;
use App\Filament\Pages\Auth\EditProfile;
use Illuminate\Contracts\View\View;

trait InteractsWithAccountNavigation
{
    abstract protected function getAccountActiveTab(): string;

    public function getHeader(): ?View
    {
        return view('filament.pages.auth.partials.account-header', [
            'activeTab' => $this->getAccountActiveTab(),
            'profileUrl' => EditProfile::getUrl(),
            'settingsUrl' => AccountSettings::getUrl(),
            'heading' => $this->getHeading(),
            'subheading' => $this->getSubheading(),
            'actions' => $this->getHeaderActions(),
        ]);
    }
}
