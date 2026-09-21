<?php

namespace App\Filament\Resources\DeliveryTerms\Pages;

use App\Filament\Concerns\HasSetupArchiveView;
use App\Filament\Resources\DeliveryTerms\DeliveryTermResource;
use App\Models\DeliveryTerm;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManageDeliveryTerms extends ManageRecords
{
    use HasSetupArchiveView;

    protected static string $resource = DeliveryTermResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->registerSetupArchiveViewHook();
    }

    protected function setupArchiveViewArchivedCount(): int
    {
        return DeliveryTerm::query()->whereNotNull('archived_at')->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => ! $this->showingArchived),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->applySetupArchiveQuery($query))
            ->recordActions([
                EditAction::make()
                    ->visible(fn (DeliveryTerm $record): bool => ! $record->isArchived()),
                DeliveryTermResource::archiveAction(),
                DeliveryTermResource::restoreAction(),
            ])
            ->emptyStateHeading(fn (): string => $this->showingArchived
                ? 'No archived delivery terms'
                : 'No delivery terms yet')
            ->emptyStateDescription(fn (): string => $this->showingArchived
                ? 'Archived terms will appear here.'
                : 'Add terms such as FOB Destination for purchase orders. These are not stored on suppliers.');
    }
}
