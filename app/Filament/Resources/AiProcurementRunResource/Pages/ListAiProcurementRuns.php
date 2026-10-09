<?php

namespace App\Filament\Resources\AiProcurementRunResource\Pages;

use App\Filament\Concerns\HasSetupArchiveView;
use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\AiProcurementRunResource;
use App\Models\AiProcurementRun;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListAiProcurementRuns extends ListRecords
{
    use HasSetupArchiveView;
    use HasSystemAdminWizardHeading;

    protected static string $resource = AiProcurementRunResource::class;

    public function getTitle(): string
    {
        return 'Recommendation History';
    }

    public function mount(): void
    {
        parent::mount();

        $this->registerSetupArchiveViewHook();
    }

    protected function setupArchiveViewArchivedCount(): int
    {
        return AiProcurementRun::query()->whereNotNull('archived_at')->count();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return 'Each row is a saved AI analysis run. Click any row to view flagged items and manage its approval status.';
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->applySetupArchiveQuery($query))
            ->emptyStateHeading(fn (): string => $this->showingArchived
                ? 'No archived AI runs'
                : 'No AI runs yet')
            ->emptyStateDescription(fn (): string => $this->showingArchived
                ? 'Archived runs will appear here.'
                : 'Go to Procurement Recommendations and click "Generate Recommendation" to create your first AI analysis.');
    }
}
