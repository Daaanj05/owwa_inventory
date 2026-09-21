<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Concerns\HasSetupArchiveView;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManageSuppliers extends ManageRecords
{
    use HasSetupArchiveView;

    protected static string $resource = SupplierResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->registerSetupArchiveViewHook();
    }

    protected function setupArchiveViewArchivedCount(): int
    {
        return Supplier::query()->whereNotNull('archived_at')->count();
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
                    ->visible(fn (Supplier $record): bool => ! $record->isArchived()),
                SupplierResource::archiveAction(),
                SupplierResource::restoreAction(),
            ])
            ->emptyStateHeading(fn (): string => $this->showingArchived
                ? 'No archived suppliers'
                : 'No suppliers yet')
            ->emptyStateDescription(fn (): string => $this->showingArchived
                ? 'Archived suppliers will appear here.'
                : 'Add suppliers with TIN and addresses for purchase orders.');
    }
}
