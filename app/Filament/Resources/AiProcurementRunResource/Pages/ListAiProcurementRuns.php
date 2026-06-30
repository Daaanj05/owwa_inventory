<?php

namespace App\Filament\Resources\AiProcurementRunResource\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\AiProcurementRunResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAiProcurementRuns extends ListRecords
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = AiProcurementRunResource::class;

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return 'Each row is a saved AI analysis run. Click any row to view flagged items and manage its approval status.';
    }

    protected function getHeaderActions(): array
    {
        $showingArchived = request('archived') === '1';

        return [
            Actions\Action::make('toggleArchived')
                ->label($showingArchived ? 'Show active runs' : 'Show archived runs')
                ->icon($showingArchived ? 'heroicon-o-clock' : 'heroicon-o-archive-box')
                ->color($showingArchived ? 'gray' : 'warning')
                ->url(fn () => AiProcurementRunResource::getUrl('index', [
                    'archived' => $showingArchived ? null : 1,
                ])),
        ];
    }
}
