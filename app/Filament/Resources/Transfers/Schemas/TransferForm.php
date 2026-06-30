<?php

namespace App\Filament\Resources\Transfers\Schemas;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\TransferItemOptionsService;
use App\Support\CustodianOfficeScope;
use App\Support\OwwaReferenceLabels;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class TransferForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeActive = fn ($query) => $query->active();

        return $schema
            ->columns(1)
            ->components([
                Placeholder::make('transfer_workflow_hint')
                    ->hiddenLabel()
                    ->content('Transfers move stock between offices. To issue items to a department or employee within your office, use Issuance.')
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'owwa-transfer-workflow-hint']),

                Section::make('Step 1 — Offices')
                    ->description('Choose where stock is leaving and where it is going.')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('from_office_id')
                            ->label('From office')
                            ->relationship(
                                'fromOffice',
                                'name',
                                $scopeActive,
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => CustodianOfficeScope::inventoryOfficeId())
                            ->helperText('Select where stock is leaving. You manage all regional and satellite offices; stock is checked at the office you choose.')
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                $set('item_id', null);
                                if (filled($state) && (int) $get('to_office_id') === (int) $state) {
                                    $set('to_office_id', null);
                                }
                            }),
                        Select::make('to_office_id')
                            ->label('To office')
                            ->options(function (Get $get): array {
                                $fromOfficeId = $get('from_office_id');
                                if (blank($fromOfficeId)) {
                                    return [];
                                }

                                return Office::query()
                                    ->active()
                                    ->whereKeyNot((int) $fromOfficeId)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(fn (Get $get): bool => blank($get('from_office_id')))
                            ->rules(['different:from_office_id'])
                            ->validationMessages([
                                'different' => 'Destination office must be different from the source office.',
                            ])
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('item_id', null)),
                    ])
                    ->columns(2),

                Section::make('Step 2 — Item & quantity')
                    ->description('Only items with inventory history at the source office are listed.')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('reference_code')
                            ->label(OwwaReferenceLabels::transfer())
                            ->disabled()
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->columnSpanFull(),
                        Select::make('item_category_filter')
                            ->label('Category')
                            ->options(fn (): array => cache()->remember(
                                'item_categories.options',
                                3600,
                                fn (): array => ItemCategory::query()->orderBy('name')->pluck('name', 'id')->toArray()
                            ))
                            ->placeholder('All categories')
                            ->default(fn (): ?int => self::activeCategoryFilter())
                            ->disabled(fn (): bool => self::isCategoryScoped())
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(fn (Set $set) => $set('item_id', null)),
                        Select::make('item_id')
                            ->label('Item')
                            ->options(function (Get $get): array {
                                $fromOfficeId = $get('from_office_id');
                                if (blank($fromOfficeId) || blank($get('to_office_id'))) {
                                    return [];
                                }

                                $categoryId = $get('item_category_filter');

                                return app(TransferItemOptionsService::class)->optionsForFromOffice(
                                    (int) $fromOfficeId,
                                    filled($categoryId) ? (int) $categoryId : null,
                                );
                            })
                            ->required()
                            ->searchable()
                            ->live()
                            ->disabled(fn (Get $get): bool => blank($get('from_office_id')) || blank($get('to_office_id')))
                            ->placeholder(fn (Get $get): string => blank($get('from_office_id')) || blank($get('to_office_id'))
                                ? 'Select offices first'
                                : 'Select an item')
                            ->helperText(function (Get $get): ?string {
                                $itemId = $get('item_id');
                                $fromOfficeId = $get('from_office_id');
                                if (blank($itemId) || blank($fromOfficeId)) {
                                    return null;
                                }

                                $stock = app(TransferItemOptionsService::class)->availableStock((int) $itemId, (int) $fromOfficeId);

                                return $stock === 0
                                    ? 'No stock available — increase stock before transferring.'
                                    : null;
                            }),
                        Placeholder::make('available_stock_preview')
                            ->label('Available at source office')
                            ->content(function (Get $get): string {
                                $itemId = $get('item_id');
                                $fromOfficeId = $get('from_office_id');
                                if (blank($itemId) || blank($fromOfficeId)) {
                                    return '—';
                                }

                                $stock = app(TransferItemOptionsService::class)->availableStock((int) $itemId, (int) $fromOfficeId);

                                return (string) $stock;
                            })
                            ->visible(fn (Get $get): bool => filled($get('item_id')) && filled($get('from_office_id'))),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(function (Get $get, $livewire): ?int {
                                $itemId = $get('item_id');
                                $fromOfficeId = $get('from_office_id');
                                if (blank($itemId) || blank($fromOfficeId)) {
                                    return null;
                                }

                                $stock = app(TransferItemOptionsService::class)->availableStock((int) $itemId, (int) $fromOfficeId);

                                if (method_exists($livewire, 'getRecord')) {
                                    $record = $livewire->getRecord();
                                    if ($record
                                        && (int) $record->item_id === (int) $itemId
                                        && (int) $record->from_office_id === (int) $fromOfficeId) {
                                        $stock += (int) $record->quantity;
                                    }
                                }

                                return $stock > 0 ? $stock : null;
                            })
                            ->helperText(function (Get $get): ?string {
                                $itemId = $get('item_id');
                                $fromOfficeId = $get('from_office_id');
                                if (blank($itemId) || blank($fromOfficeId)) {
                                    return null;
                                }

                                $stock = app(TransferItemOptionsService::class)->availableStock((int) $itemId, (int) $fromOfficeId);

                                return "Maximum: {$stock}";
                            }),
                        DatePicker::make('transfer_date')
                            ->label('Transfer date')
                            ->required()
                            ->default(now()),
                        Select::make('condition')
                            ->label('Condition of property')
                            ->options([
                                'Serviceable' => 'Serviceable',
                                'Unserviceable' => 'Unserviceable',
                                'Good' => 'Good',
                                'Poor' => 'Poor',
                            ])
                            ->placeholder('Select condition'),
                        Select::make('transfer_type')
                            ->label('Transfer type')
                            ->options([
                                'donation' => 'Donation',
                                'relocate' => 'Relocate',
                                'reassignment' => 'Reassignment',
                                'return' => 'Return to stock',
                                'others' => 'Others',
                            ])
                            ->placeholder('Select type')
                            ->live(),
                        TextInput::make('transfer_type_other')
                            ->label('Others (specify)')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('transfer_type') === 'others'),
                    ])
                    ->columns(2),

                Section::make('Accountable officers')
                    ->description('PTR header rows 8–9 (From / To Accountable Officer). Required for PPE and semi-expendable transfers.')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('from_accountable_officer')
                            ->label('From accountable officer')
                            ->maxLength(255)
                            ->helperText('PTR cell A8'),
                        TextInput::make('to_accountable_officer')
                            ->label('To accountable officer')
                            ->maxLength(255)
                            ->helperText('PTR cell A9'),
                        Textarea::make('reason_for_transfer')
                            ->label('Reason for transfer')
                            ->rows(2)
                            ->helperText('PTR cell A43')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn (Get $get): bool => self::usesPtrForm($get('item_category_filter'))),

                Section::make('Additional details')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('stock_number_display')
                            ->label(OwwaReferenceLabels::STOCK_NO)
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => filled($get('item_id'))
                                && ! OwwaReferenceLabels::usesPropertyNumber(
                                    OwwaReferenceLabels::itemCategorySlug((int) $get('item_id'))
                                ))
                            ->afterStateHydrated(function (TextInput $component, $state, Get $get): void {
                                $itemId = $get('item_id');
                                if (blank($itemId)) {
                                    return;
                                }

                                $code = Item::query()->whereKey($itemId)->value('item_code');
                                $component->state(filled($code) ? $code : '—');
                            })
                            ->helperText(OwwaReferenceLabels::stockNumberHelperText()),
                        TextInput::make('property_number')
                            ->label(fn (Get $get): string => OwwaReferenceLabels::assetIdentifierLabel(
                                OwwaReferenceLabels::itemCategorySlug((int) $get('item_id'))
                            ))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => OwwaReferenceLabels::usesPropertyNumber(
                                OwwaReferenceLabels::itemCategorySlug((int) $get('item_id'))
                            ))
                            ->placeholder('Asset tag / property no.'),
                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->rows(2)
                            ->placeholder('Optional notes'),
                    ])
                    ->columns(2),

                Section::make('Signatories')
                    ->description('PTR rows 53–55 — Approved by, Released by, Received by (with designations on row 54).')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('approved_by_printed_name')
                            ->label('Approved by')
                            ->maxLength(255)
                            ->placeholder('Full name')
                            ->helperText('PTR B53'),
                        TextInput::make('approved_by_designation')
                            ->label('Approved by designation')
                            ->maxLength(255)
                            ->helperText('PTR A54'),
                        TextInput::make('released_by_printed_name')
                            ->label('Released by')
                            ->maxLength(255)
                            ->placeholder('Full name')
                            ->helperText('PTR F53'),
                        TextInput::make('released_by_designation')
                            ->label('Released by designation')
                            ->maxLength(255)
                            ->helperText('PTR F54'),
                        TextInput::make('received_by_printed_name')
                            ->label('Received by')
                            ->maxLength(255)
                            ->placeholder('Full name')
                            ->helperText('PTR H53'),
                        TextInput::make('received_by_designation')
                            ->label('Received by designation')
                            ->maxLength(255)
                            ->helperText('PTR H54'),
                    ])
                    ->columns(2)
                    ->visible(fn (Get $get): bool => self::usesPtrForm($get('item_category_filter'))),
            ]);
    }

    protected static function usesPtrForm(mixed $categoryId): bool
    {
        if (blank($categoryId)) {
            return false;
        }

        $category = ItemCategory::find($categoryId);
        $slug = $category?->getTemplateSlug();

        return in_array($slug, ['ppe', 'semi_expendable'], true);
    }

    protected static function isCategoryScoped(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin'
            && filled(session('active_item_category_id'));
    }

    protected static function activeCategoryFilter(): ?int
    {
        if (! self::isCategoryScoped()) {
            return null;
        }

        return (int) session('active_item_category_id');
    }
}
