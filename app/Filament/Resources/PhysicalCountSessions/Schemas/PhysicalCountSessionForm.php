<?php

namespace App\Filament\Resources\PhysicalCountSessions\Schemas;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\PhysicalCountSessions\Pages\CreatePhysicalCountSession;
use App\Filament\Resources\PhysicalCountSessions\Pages\EditPhysicalCountSession;
use App\Filament\Resources\PhysicalCountSessions\Pages\ListPhysicalCountSessions;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PhysicalCountSession;
use App\Services\InventoryStockService;
use App\Support\ConsumableInventoryType;
use App\Support\CustodianOfficeScope;
use App\Support\OfficeSignatoryDefaults;
use App\Support\OwwaReferenceLabels;
use App\Support\PhysicalCountSessionViewPresenter;
use App\Support\PpePropertyType;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PhysicalCountSessionForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeActive = fn ($query) => $query->active();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Count session')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Hidden::make('count_type')
                            ->default(fn (Get $get): string => self::resolveCountTypeForCategoryId(
                                $get('item_category_id') ?: SyncsActiveItemCategory::resolveCategoryIdFromContext(),
                            ))
                            ->dehydrated()
                            ->live(),
                        Select::make('office_id')
                            ->label('Office')
                            ->relationship(
                                'office',
                                'name',
                                fn ($query) => CustodianOfficeScope::officeQuery($query),
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => CustodianOfficeScope::inventoryOfficeId())
                            ->disabled(fn (): bool => CustodianOfficeScope::hasFixedInventoryOffice())
                            ->dehydrated()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                $defaults = OfficeSignatoryDefaults::forPhysicalCountSession((int) $state);
                                foreach ($defaults as $field => $value) {
                                    if (filled($value)) {
                                        $set($field, $value);
                                    }
                                }
                            }),
                        Select::make('item_category_id')
                            ->label('Item category')
                            ->options(fn (): array => ItemCategory::query()->whereNull('archived_at')->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (): mixed => SyncsActiveItemCategory::resolveCategoryIdFromContext())
                            ->searchable()
                            ->live()
                            ->visible(fn (): bool => ! self::isCategoryScoped())
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                $set('count_type', self::resolveCountTypeForCategoryId((int) $state));
                            }),
                        DatePicker::make('count_date')
                            ->label('As at date')
                            ->required()
                            ->default(now()),
                        Select::make('inventory_type')
                            ->label('Inventory type')
                            ->options(ConsumableInventoryType::options())
                            ->required(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCI)
                            ->searchable()
                            ->live()
                            ->helperText('Scopes RPCI lines and prints as Type of Inventory Item on Appendix 66.')
                            ->visible(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCI)
                            ->dehydrated(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCI)
                            ->afterStateUpdated(function ($state, callable $set): void {
                                $set('inventory_type_label', ConsumableInventoryType::label($state));
                            })
                            ->columnSpanFull(),
                        Hidden::make('inventory_type_label')
                            ->dehydrated(),
                        Select::make('ppe_type')
                            ->label('Type of PPE')
                            ->options(PpePropertyType::options())
                            ->searchable()
                            ->live()
                            ->helperText('Scopes RPCPPE expected assets and prints as Type of PPE on Appendix 73.')
                            ->visible(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCPPE)
                            ->dehydrated(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCPPE)
                            ->afterStateUpdated(function ($state, callable $set): void {
                                $set('inventory_type_label', PpePropertyType::propertyTypeLabel($state));
                            })
                            ->columnSpanFull(),
                        TextInput::make('accountable_officer_name')
                            ->label('Accountable officer'),
                        TextInput::make('accountable_officer_designation')
                            ->label('Designation'),
                        DatePicker::make('date_of_assumption')
                            ->label('Date of assumption'),
                    ]),
                Section::make('Signatories')
                    ->description(fn (Get $get): string => match ($get('count_type')) {
                        PhysicalCountSession::TYPE_RPCPPE => 'Appendix 73 RPCPPE — Certified by (D39), Approved by (G39), Verified by (K39).',
                        PhysicalCountSession::TYPE_RPCSP => 'Annex A.8 RPCSP — Certified by (C39), Approved by (F39), Verified by (J39) on property-class sheets.',
                        default => 'Appendix 66 RPCI — Certified by (C39), Approved by (G39), Verified by (K39).',
                    })
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('certified_by_printed_name')->label('Certified by'),
                        TextInput::make('approved_by_printed_name')->label('Approved by'),
                        TextInput::make('verified_by_printed_name')->label('Verified by'),
                    ]),
                Section::make('QR counting workflow')
                    ->description(fn (Get $get): string => $get('count_type') === PhysicalCountSession::TYPE_RPCI
                        ? 'Stock QR scanning — scan shelf/stock labels, then enter counted quantity.'
                        : 'Property-tag scanning (PPE and semi-expendable)')
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => in_array($get('count_type'), [
                        PhysicalCountSession::TYPE_RPCI,
                        PhysicalCountSession::TYPE_RPCPPE,
                        PhysicalCountSession::TYPE_RPCSP,
                    ], true))
                    ->schema([
                        Placeholder::make('qr_workflow_steps')
                            ->hiddenLabel()
                            ->content(fn (): \Illuminate\Support\HtmlString => PhysicalCountSessionViewPresenter::qrWorkflowStepsHtml())
                            ->columnSpanFull(),
                    ]),
                Section::make('Count lines')
                    ->description(fn (Get $get): ?string => match ($get('count_type')) {
                        PhysicalCountSession::TYPE_RPCPPE, PhysicalCountSession::TYPE_RPCSP => 'Shown on edit only for corrections. On create, use Load expected assets after saving.',
                        PhysicalCountSession::TYPE_RPCI => 'Add lines manually or use Load stock lines / stock QR scan after saving.',
                        default => null,
                    })
                    ->columnSpanFull()
                    ->visible(fn (Get $get, $livewire): bool => self::shouldShowCountLines($get, $livewire))
                    ->schema([
                        Repeater::make('lines')
                            ->relationship('lines')
                            ->label('Items counted')
                            ->schema([
                                Select::make('item_id')
                                    ->label('Item')
                                    ->options(function (Get $get): array {
                                        $categoryId = $get('../../item_category_id');
                                        $countType = $get('../../count_type');
                                        $query = Item::query()
                                            ->active()
                                            ->orderBy('name');
                                        if (filled($categoryId)) {
                                            $query->where('item_category_id', (int) $categoryId);
                                        }

                                        if ($countType === PhysicalCountSession::TYPE_RPCI && filled($get('../../inventory_type'))) {
                                            $query->where('inventory_type', (string) $get('../../inventory_type'));
                                        }

                                        if ($countType === PhysicalCountSession::TYPE_RPCPPE && filled($get('../../ppe_type'))) {
                                            $query->where('ppe_type', (string) $get('../../ppe_type'));
                                        }

                                        if ($countType === PhysicalCountSession::TYPE_RPCSP && filled($get('../../property_class'))) {
                                            $query->where('property_class', (string) $get('../../property_class'));
                                        }

                                        return $query->pluck('name', 'id')->all();
                                    })
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                                        if (blank($state)) {
                                            return;
                                        }
                                        $item = Item::query()->find($state);
                                        if (! $item) {
                                            return;
                                        }
                                        $officeId = $get('../../office_id');
                                        $set('article', $item->name);
                                        $set('description', $item->description);
                                        $set('stock_number', $item->item_code);
                                        $set('unit_of_measure', $item->unit);
                                        if ($officeId) {
                                            $stock = app(InventoryStockService::class)->getStock((int) $item->id, (int) $officeId);
                                            $set('balance_per_card', max(0, $stock));
                                            $set('on_hand_count', 0);
                                        }
                                    }),
                                TextInput::make('article')->label('Article'),
                                TextInput::make('description')->label('Description'),
                                TextInput::make('stock_number')->label('Stock / property no.'),
                                TextInput::make('property_number')
                                    ->label(fn (Get $get): string => OwwaReferenceLabels::assetIdentifierLabel(
                                        match ($get('../../count_type')) {
                                            PhysicalCountSession::TYPE_RPCPPE => 'ppe',
                                            PhysicalCountSession::TYPE_RPCSP => 'semi_expendable',
                                            default => null,
                                        }
                                    )),
                                TextInput::make('unit_of_measure')->label('Measurement unit'),
                                TextInput::make('balance_per_card')
                                    ->label('Balance per card')
                                    ->numeric()
                                    ->default(0)
                                    ->helperText('Book balance from stock card / system records.'),
                                TextInput::make('on_hand_count')
                                    ->label('On hand per count')
                                    ->numeric()
                                    ->default(0)
                                    ->helperText('Quantity you physically counted (scanner fills this for PPE/semi; stock QR prompts qty for consumables).'),
                                Textarea::make('remarks')->rows(1),
                            ])
                            ->columns(3)
                            ->minItems(function (Get $get, $livewire): int {
                                if (! ($livewire instanceof EditPhysicalCountSession)
                                    && in_array($get('count_type'), [PhysicalCountSession::TYPE_RPCPPE, PhysicalCountSession::TYPE_RPCSP], true)) {
                                    return 0;
                                }

                                return 1;
                            })
                            ->addActionLabel('Add item line')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function resolveCountTypeForCategoryId(int|string|null $categoryId): string
    {
        if (blank($categoryId)) {
            return PhysicalCountSession::TYPE_RPCI;
        }

        $category = ItemCategory::query()->find((int) $categoryId);

        return match ($category?->getTemplateSlug()) {
            'ppe' => PhysicalCountSession::TYPE_RPCPPE,
            'semi_expendable' => PhysicalCountSession::TYPE_RPCSP,
            default => PhysicalCountSession::TYPE_RPCI,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultCreateFormData(?int $categoryId = null): array
    {
        $categoryId ??= SyncsActiveItemCategory::resolveCategoryIdFromContext();
        $officeId = CustodianOfficeScope::inventoryOfficeId();

        return OfficeSignatoryDefaults::mergeNonBlank(
            OfficeSignatoryDefaults::forPhysicalCountSession($officeId),
            [
                'item_category_id' => $categoryId > 0 ? $categoryId : null,
                'count_type' => self::resolveCountTypeForCategoryId($categoryId),
                'count_date' => now()->toDateString(),
                'office_id' => $officeId,
            ],
        );
    }

    public static function shouldShowCountLines(Get $get, mixed $livewire): bool
    {
        if (! in_array($get('count_type'), [PhysicalCountSession::TYPE_RPCPPE, PhysicalCountSession::TYPE_RPCSP], true)) {
            return true;
        }

        if ($livewire instanceof EditPhysicalCountSession) {
            return true;
        }

        if ($livewire instanceof CreatePhysicalCountSession) {
            return false;
        }

        if ($livewire instanceof ListPhysicalCountSessions && filled($livewire->mountedActionRecord ?? null)) {
            return true;
        }

        return false;
    }

    protected static function isCategoryScoped(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin'
            && (filled(request()->query('category')) || filled(session('active_item_category_id')));
    }
}
