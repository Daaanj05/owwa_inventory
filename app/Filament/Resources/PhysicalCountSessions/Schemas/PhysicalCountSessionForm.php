<?php

namespace App\Filament\Resources\PhysicalCountSessions\Schemas;

use App\Filament\Resources\PhysicalCountSessions\Pages\EditPhysicalCountSession;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PhysicalCountSession;
use App\Services\InventoryStockService;
use App\Support\CustodianOfficeScope;
use App\Support\ItemPropertyClass;
use App\Support\OfficeSignatoryDefaults;
use Filament\Forms\Components\DatePicker;
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
                        Select::make('count_type')
                            ->label('Report form')
                            ->options([
                                PhysicalCountSession::TYPE_RPCI => 'Appendix 66 - RPCI (Inventories)',
                                PhysicalCountSession::TYPE_RPCPPE => 'Appendix 73 - RPCPPE (PPE)',
                                PhysicalCountSession::TYPE_RPCSP => 'Annex A.8 - RPCSP (Semi-expendable)',
                            ])
                            ->required()
                            ->live()
                            ->helperText(fn (Get $get): string => in_array($get('count_type'), [PhysicalCountSession::TYPE_RPCPPE, PhysicalCountSession::TYPE_RPCSP], true)
                                ? 'QR scanning is available for PPE and semi-expendable. Save the session, then use Load expected assets and Scan with phone.'
                                : 'Consumables use manual count lines below. QR scanning is not used for RPCI.'),
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
                            ->default(fn (): mixed => session('active_item_category_id'))
                            ->searchable()
                            ->live(),
                        DatePicker::make('count_date')
                            ->label('As at date')
                            ->required()
                            ->default(now()),
                        TextInput::make('inventory_type_label')
                            ->label('Type of inventory / property')
                            ->placeholder(fn (Get $get): string => match ($get('count_type')) {
                                PhysicalCountSession::TYPE_RPCPPE => 'e.g. ICT, Office Equipment, Medical Equipment',
                                PhysicalCountSession::TYPE_RPCSP => 'e.g. ICT, Office Equipment, Medical Equipment',
                                default => 'e.g. Office Supplies Inventory, Medical/Dental/Laboratory Supplies Inventory',
                            })
                            ->helperText(fn (Get $get): string => match ($get('count_type')) {
                                PhysicalCountSession::TYPE_RPCPPE => 'Printed on Appendix 73 as “Type of Property, Plant and Equipment”.',
                                PhysicalCountSession::TYPE_RPCSP => 'Printed on Annex A.8 as “Type of Property, Plant and Equipment”. Prefer selecting Property class below for the correct Excel tab.',
                                default => 'Printed on Appendix 66 as “Type of Inventory Item” (e.g. Office Supplies Inventory, Accountable Forms Inventory).',
                            })
                            ->columnSpanFull(),
                        Select::make('property_class')
                            ->label('Property class (semi-expendable tab)')
                            ->options(ItemPropertyClass::options())
                            ->searchable()
                            ->placeholder('Infer from count lines or type label')
                            ->helperText('Select when exporting Annex A.8 RPCSP so the correct property-class sheet tab is used.')
                            ->visible(fn (Get $get): bool => $get('count_type') === PhysicalCountSession::TYPE_RPCSP),
                        TextInput::make('fund_cluster')
                            ->label('Fund cluster'),
                        TextInput::make('accountable_officer_name')
                            ->label('Accountable officer'),
                        TextInput::make('accountable_officer_designation')
                            ->label('Designation'),
                        DatePicker::make('date_of_assumption')
                            ->label('Date of assumption'),
                    ]),
                Section::make('Signatories')
                    ->description(fn (Get $get): string => match ($get('count_type')) {
                        PhysicalCountSession::TYPE_RPCPPE => 'Appendix 73 RPCPPE — Certified by (C35), Approved by (G35), Verified by (C37).',
                        PhysicalCountSession::TYPE_RPCSP => 'Annex A.8 RPCSP — Certified by (B35), Approved by (F35), Verified by (B37).',
                        default => 'Appendix 66 RPCI — Certified by (B35), Approved by (F35), Verified by (B37).',
                    })
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('certified_by_printed_name')->label('Certified by'),
                        TextInput::make('approved_by_printed_name')->label('Approved by'),
                        TextInput::make('verified_by_printed_name')->label('Verified by'),
                    ]),
                Section::make('QR counting workflow')
                    ->description('Property-tag scanning (PPE and semi-expendable only)')
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => in_array($get('count_type'), [PhysicalCountSession::TYPE_RPCPPE, PhysicalCountSession::TYPE_RPCSP], true))
                    ->schema([
                        Placeholder::make('qr_workflow_steps')
                            ->content("After you save this session:\n\n1. Load expected assets — pulls issued property numbers for the selected office (book balance, on-hand starts at 0).\n2. Print QR labels — from issuances or bulk from this session.\n3. Scan with phone — each tag found increments on-hand count.\n4. Review shortages/overages on the session view, then export the OWWA form.\n\nCount lines are added automatically; you do not need to enter items manually on this screen.")
                            ->columnSpanFull(),
                    ]),
                Section::make('Count lines')
                    ->description(fn (Get $get): ?string => in_array($get('count_type'), [PhysicalCountSession::TYPE_RPCPPE, PhysicalCountSession::TYPE_RPCSP], true)
                        ? 'Shown on edit only for corrections. On create, use Load expected assets after saving.'
                        : null)
                    ->columnSpanFull()
                    ->hidden(function (Get $get, $livewire): bool {
                        if (! in_array($get('count_type'), [PhysicalCountSession::TYPE_RPCPPE, PhysicalCountSession::TYPE_RPCSP], true)) {
                            return false;
                        }

                        return ! ($livewire instanceof EditPhysicalCountSession);
                    })
                    ->schema([
                        Repeater::make('lines')
                            ->relationship('lines')
                            ->label('Items counted')
                            ->schema([
                                Select::make('item_id')
                                    ->label('Item')
                                    ->options(function (Get $get): array {
                                        $categoryId = $get('../../item_category_id');
                                        $query = Item::query()
                                            ->active()
                                            ->orderBy('name');
                                        if (filled($categoryId)) {
                                            $query->where('item_category_id', (int) $categoryId);
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
                                            $set('on_hand_count', max(0, $stock));
                                        }
                                    }),
                                TextInput::make('article')->label('Article'),
                                TextInput::make('description')->label('Description'),
                                TextInput::make('stock_number')->label('Stock / property no.'),
                                TextInput::make('property_number')->label('Property number'),
                                TextInput::make('unit_of_measure')->label('Measurement unit'),
                                TextInput::make('balance_per_card')
                                    ->label('Balance per card')
                                    ->numeric()
                                    ->default(0),
                                TextInput::make('on_hand_count')
                                    ->label('On hand per count')
                                    ->numeric()
                                    ->default(0),
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
}
