<?php

namespace App\Filament\Resources\AiProcurementRunResource\Pages;

use App\Filament\Concerns\HasSetupArchiveView;
use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\AiProcurementRunResource;
use App\Models\AiProcurementRun;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
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
        return [
            Action::make('exportHistoryPdf')
                ->label('Export PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->isSupplyCustodian() ?? false)
                ->modalHeading('Export recommendation history')
                ->modalSubmitActionLabel('Export')
                ->fillForm(fn (): array => [
                    'date_from' => now()->startOfYear()->toDateString(),
                    'date_to' => now()->endOfYear()->toDateString(),
                    'status' => null,
                ])
                ->schema([
                    Grid::make(2)
                        ->schema([
                            DatePicker::make('date_from')
                                ->label('From'),
                            DatePicker::make('date_to')
                                ->label('Until')
                                ->afterOrEqual('date_from'),
                            Select::make('status')
                                ->label('Status')
                                ->placeholder('All statuses')
                                ->options([
                                    'pending' => 'Pending',
                                    'approved' => 'Approved',
                                    'failed' => 'Failed',
                                    'processing' => 'Processing',
                                ])
                                ->columnSpanFull(),
                        ]),
                ])
                ->action(function (array $data): void {
                    $url = route('ai-procurement-runs.export-history-pdf', array_filter([
                        'date_from' => $data['date_from'] ?? null,
                        'date_to' => $data['date_to'] ?? null,
                        'status' => $data['status'] ?? null,
                        'archived' => $this->showingArchived ? 1 : null,
                    ], fn (mixed $value): bool => filled($value)));

                    OwwaExportBusyDispatcher::start(
                        $this,
                        $url,
                        'Preparing PDF export…',
                        'Building the recommendation history PDF…',
                    );
                }),
        ];
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
