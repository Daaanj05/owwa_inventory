<?php

namespace App\Filament\Widgets;

use App\Models\FiscalYear;
use App\Services\FiscalYearService;
use Filament\Widgets\Widget;

class ActiveFiscalYearWidget extends Widget
{
    protected static ?int $sort = -5;

    protected string $view = 'filament.widgets.active-fiscal-year-widget';

    public static function canView(): bool
    {
        return false;
    }

    public ?int $fiscalYearId = null;

    public bool $forceChooser = false;

    public function mount(): void
    {
        $service = app(FiscalYearService::class);
        $current = $service->current();

        if ($current) {
            $this->fiscalYearId = $current->id;
        }
    }

    public function getYearsProperty()
    {
        return FiscalYear::orderByDesc('start_date')->get();
    }

    public function apply(): void
    {
        if ($this->fiscalYearId) {
            app(FiscalYearService::class)->setCurrent($this->fiscalYearId);
        }

        $this->redirect(request()->fullUrl());
    }

    public function changeYear(): void
    {
        app(FiscalYearService::class)->setCurrent(null);
        $this->fiscalYearId = null;
        $this->forceChooser = true;
    }
}

