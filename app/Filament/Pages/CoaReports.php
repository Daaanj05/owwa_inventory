<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use UnitEnum;

class CoaReports extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'COA reports';

    protected static ?string $title = 'COA reports';

    protected string $view = 'filament.pages.coa-reports';

    public static function getNavigationLabel(): string
    {
        return 'COA reports';
    }

    public function getTitle(): string
    {
        return 'COA reports';
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return ['owwa-coa-reports-page'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('stockLevel')
                ->label('Download stock level report (PDF)')
                ->url(route('reports.coa.stock-level'))
                ->openUrlInNewTab(false),
            Action::make('issuance')
                ->label('Download issuance report (PDF)')
                ->url(route('reports.coa.issuance'))
                ->openUrlInNewTab(false),
        ];
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }
}
