<?php

namespace App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class WelcomeWidget extends Widget
{
    protected static ?int $sort = -4;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.welcome-widget';

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Filament::auth()->check();
    }
}
