<?php

namespace App\Filament\Resources\Requisitions\Schemas;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Services\RequisitionCompileService;
use App\Support\OwwaReferenceLabels;
use App\Support\SupplyOfficeResolver;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class RequisitionForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeActive = fn ($query) => $query->active();
        /** @var User|null $user */
        $user = Filament::auth()->user();
        $isCustodian = $user?->isSupplyCustodian() ?? false;
        $isEmployee = $user?->isEmployee() ?? false;
        $isUnitConsolidator = $user?->isUnitConsolidator() ?? false;
        $needsOfficeSelection = $isEmployee && blank($user?->office_id);

        return $schema
            ->components([
                Section::make('Compile employee requests')
                    ->description('Select approved employee requisitions to include. Line items below will merge from your selection; you can adjust quantities before sending to the Supply Custodian.')
                    ->visible(fn (string $operation): bool => $operation === 'create' && $isUnitConsolidator)
                    ->columnSpanFull()
                    ->schema([
                        CheckboxList::make('source_requisition_ids')
                            ->label('Employee requisitions to include')
                            ->options(fn (): array => $user instanceof User
                                ? app(RequisitionCompileService::class)->eligibleEmployeeRequisitionOptions($user)
                                : [])
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                                'xl' => 4,
                            ])
                            ->live()
                            ->afterStateUpdated(function (?array $state, Set $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                $requisitions = Requisition::query()
                                    ->whereIn('id', $state)
                                    ->get();

                                $merged = app(RequisitionCompileService::class)->mergedLineItems($requisitions);
                                $set('items', app(RequisitionCompileService::class)->mergedLineItemsAsRepeaterState($merged));
                            })
                            ->helperText('Only approved requests that have not yet been sent to the Supply Custodian are listed.'),
                    ]),
                Section::make('Requisition details')
                    ->description($isCustodian
                        ? 'Manage this requisition.'
                        : ($isUnitConsolidator
                            ? 'File a requisition to the Supply Custodian. Purpose maps to the RIS header (cell A32).'
                            : 'Submit a request for inventory items.'))
                    ->visible(fn (string $operation): bool => ! $isEmployee || $needsOfficeSelection || $operation === 'edit')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('reference_code')
                            ->label(OwwaReferenceLabels::requisition())
                            ->disabled()
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->columnSpanFull(),
                        Select::make('office_id')
                            ->label('Office')
                            ->relationship('office', 'name', $scopeActive)
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default($user?->office_id)
                            ->dehydrated()
                            ->hidden(fn (): bool => $isEmployee && ! $needsOfficeSelection)
                            ->disabled(! $isCustodian && filled($user?->office_id)),
                        Select::make('department_id')
                            ->label('Department')
                            ->relationship('department', 'name', $scopeActive)
                            ->searchable()
                            ->preload()
                            ->placeholder('None')
                            ->default($user?->department_id)
                            ->dehydrated()
                            ->hidden(fn (): bool => $isEmployee && filled($user?->department_id))
                            ->disabled(! $isCustodian && filled($user?->department_id)),
                        Select::make('status')
                            ->label('Status')
                            ->options([
                                Requisition::STATUS_PENDING => 'Pending',
                                Requisition::STATUS_ACCEPTED => 'Accepted',
                                Requisition::STATUS_REJECTED => 'Rejected',
                            ])
                            ->default(Requisition::STATUS_PENDING)
                            ->required()
                            ->visible(fn (): bool => $isCustodian),
                        Textarea::make('purpose')
                            ->label('Purpose (RIS)')
                            ->columnSpanFull()
                            ->rows(2)
                            ->required(fn (): bool => $isUnitConsolidator)
                            ->visible(fn (): bool => ! $isEmployee),
                    ]),
                Section::make('Request Items')
                    ->description($isEmployee
                        ? 'Your office and department are taken from your account. Select a category on each line before choosing an item. Stock shown is at the regional supply office. Per-line remarks map to RIS column H.'
                        : ($isCustodian
                            ? 'Add the items you want to request.'
                            : 'Select a category on each line before choosing an item. Stock shown is at the regional supply office. Per-line remarks map to RIS column H.'))
                    ->visible(fn (): bool => ! $isCustodian)
                    ->columnSpanFull()
                    ->schema([
                        self::requestItemsRepeater($isCustodian),
                    ]),
            ]);
    }

    private static function requestItemsRepeater(bool $isCustodian): Repeater
    {
        return Repeater::make('items')
            ->relationship('items')
            ->label('Items')
            ->schema([
                Select::make('item_category_id')
                    ->label('Category')
                    ->options(fn (): array => ItemCategory::query()
                        ->whereNull('archived_at')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required()
                    ->dehydrated(false)
                    ->afterStateUpdated(function ($state, callable $set): void {
                        $set('item_id', null);
                    })
                    ->afterStateHydrated(function (Select $component, $state, ?RequisitionItem $record): void {
                        if (filled($state) || $record === null) {
                            return;
                        }

                        $record->loadMissing('item');
                        $component->state($record->item?->item_category_id);
                    }),
                Select::make('item_id')
                    ->label('Item')
                    ->options(function (Get $get): array {
                        $categoryId = $get('item_category_id');

                        if (blank($categoryId)) {
                            return [];
                        }

                        return Item::query()
                            ->active()
                            ->where('item_category_id', (int) $categoryId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->disabled(fn (Get $get): bool => blank($get('item_category_id')))
                    ->placeholder(fn (Get $get): string => blank($get('item_category_id'))
                        ? 'Choose a category first'
                        : 'Select an item'),
                Placeholder::make('regional_stock_available')
                    ->label('Available at regional supply')
                    ->content(function (Get $get): string {
                        $itemId = $get('item_id');
                        if (blank($itemId)) {
                            return '—';
                        }

                        $supplyOfficeId = app(SupplyOfficeResolver::class)->resolve();
                        if ($supplyOfficeId === null) {
                            return '—';
                        }

                        $stock = app(InventoryStockService::class)->getStock((int) $itemId, $supplyOfficeId);

                        return (string) max(0, $stock);
                    })
                    ->visible(fn (): bool => ! $isCustodian),
                TextInput::make('quantity')
                    ->label('Qty')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                TextInput::make('stock_available')
                    ->label('Stock available')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (): bool => $isCustodian)
                    ->afterStateHydrated(function (TextInput $component, Get $get, $state): void {
                        if ($state !== null || blank($get('item_id'))) {
                            return;
                        }
                        $officeId = Filament::auth()->user()?->office_id;
                        if (! $officeId) {
                            return;
                        }
                        $stock = app(InventoryStockService::class)->getStock((int) $get('item_id'), (int) $officeId);
                        $component->state(max(0, $stock));
                    }),
                TextInput::make('quantity_issued')
                    ->label('Qty issued')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (): bool => $isCustodian),
                TextInput::make('remarks')
                    ->label('Remarks (RIS line)')
                    ->placeholder('Optional — maps to RIS column H')
                    ->columnSpanFull(),
                TextInput::make('issue_remarks')
                    ->label('Issue remarks')
                    ->placeholder('Optional')
                    ->columnSpanFull()
                    ->visible(fn (): bool => $isCustodian),
            ])
            ->columns(2)
            ->grid([
                'default' => 1,
                'lg' => 2,
            ])
            ->itemLabel(function (array $state): ?string {
                if (blank($state['item_id'] ?? null)) {
                    return null;
                }

                return Item::query()->find($state['item_id'])?->name;
            })
            ->minItems(1)
            ->addActionLabel('Add another item');
    }

    /**
     * @return array<string, mixed>
     */
    public static function catalogPrefillState(int $itemId, ?int $categoryId = null): array
    {
        $item = Item::query()->find($itemId);

        if ($item === null) {
            return [];
        }

        $resolvedCategoryId = $categoryId > 0 ? $categoryId : $item->item_category_id;

        return [
            'items' => [
                [
                    'item_category_id' => $resolvedCategoryId,
                    'item_id' => $item->id,
                    'quantity' => 1,
                ],
            ],
        ];
    }
}
