<?php

namespace App\Filament\Resources\DeliveryTerms\Pages;

use App\Filament\Concerns\HasSearchRowToolbarActions;
use App\Filament\Concerns\HasSetupArchiveView;
use App\Filament\Resources\DeliveryTerms\DeliveryTermResource;
use App\Models\DeliveryTerm;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManageDeliveryTerms extends ManageRecords
{
    use HasSearchRowToolbarActions;
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

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'owwa-setup-archive-toggle',
            'owwa-search-row-toolbar',
            'owwa-setup-create-toolbar',
        ];
    }

    /**
     * @return list<array{label: string, action?: string, url?: string, style?: string}>
     */
    protected function searchRowToolbarButtons(): array
    {
        if ($this->showingArchived) {
            return [];
        }

        return [
            [
                'label' => 'New Delivery Term',
                'action' => 'create',
                'style' => 'primary',
            ],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->modalWidth(Width::Large)
                ->extraModalWindowAttributes(['class' => 'owwa-supplier-modal'])
                ->visible(fn (): bool => ! $this->showingArchived),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->applySetupArchiveQuery($query))
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::Large)
                    ->extraModalWindowAttributes(['class' => 'owwa-supplier-modal'])
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
