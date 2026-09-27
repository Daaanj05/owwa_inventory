<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Concerns\HasSearchRowToolbarActions;
use App\Filament\Concerns\HasSetupArchiveView;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use App\Models\SupplierAddress;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManageSuppliers extends ManageRecords
{
    use HasSearchRowToolbarActions;
    use HasSetupArchiveView;

    protected static string $resource = SupplierResource::class;

    protected ?string $pendingSupplierAddress = null;

    protected ?string $pendingSecondaryAddress = null;

    public function mount(): void
    {
        parent::mount();

        $this->registerSetupArchiveViewHook();
    }

    protected function setupArchiveViewArchivedCount(): int
    {
        return Supplier::query()->whereNotNull('archived_at')->count();
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
                'label' => 'New Supplier',
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
                ->visible(fn (): bool => ! $this->showingArchived)
                ->mutateFormDataUsing(fn (array $data): array => $this->pullSupplierAddresses($data))
                ->after(function (Supplier $record): void {
                    $this->storePendingSupplierAddresses($record);
                }),
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
                    ->fillForm(fn (Supplier $record): array => $this->supplierFormData($record))
                    ->mutateFormDataUsing(fn (array $data): array => $this->pullSupplierAddresses($data))
                    ->after(function (Supplier $record): void {
                        $this->storePendingSupplierAddresses($record);
                    })
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function pullSupplierAddresses(array $data): array
    {
        $this->pendingSupplierAddress = trim((string) ($data['address'] ?? ''));
        $this->pendingSecondaryAddress = trim((string) ($data['secondary_address'] ?? ''));
        unset($data['address'], $data['secondary_address']);

        return $data;
    }

    protected function storePendingSupplierAddresses(Supplier $supplier): void
    {
        SupplierAddress::replaceFromForm(
            $supplier,
            (string) $this->pendingSupplierAddress,
            $this->pendingSecondaryAddress,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function supplierFormData(Supplier $record): array
    {
        $addresses = $record->addresses()->orderByDesc('is_default')->orderBy('id')->get();
        $primary = $addresses->first(fn (SupplierAddress $address): bool => $address->is_default) ?? $addresses->first();
        $secondary = $addresses->first(fn (SupplierAddress $address): bool => $primary === null || $address->id !== $primary->id);

        return [
            'name' => $record->name,
            'tin' => $record->tin,
            'address' => $primary?->address,
            'secondary_address' => $secondary?->address,
        ];
    }
}
