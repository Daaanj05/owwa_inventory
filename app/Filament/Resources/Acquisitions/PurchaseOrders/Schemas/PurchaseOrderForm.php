<?php

namespace App\Filament\Resources\Acquisitions\PurchaseOrders\Schemas;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierAddress;
use App\Support\ModeOfProcurementOptions;
use App\Support\SupplyOfficeResolver;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Purchase request')
                    ->description('Linked approved PR details (read-only).')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('pr_number_display')
                            ->label('PR No.')
                            ->content(fn (?PurchaseOrder $record): string => $record?->purchaseRequest?->pr_number ?: '—'),
                        Placeholder::make('pr_purpose_display')
                            ->label('Purpose')
                            ->content(fn (?PurchaseOrder $record): string => $record?->purchaseRequest?->purpose ?: '—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Purchase order')
                    ->description('Fill supplier and delivery details, then save and submit for export.')
                    ->columns(2)
                    ->schema(self::headerFields()),
                Section::make('Line items')
                    ->description('Select which PR lines and quantities to order. Unordered lines stay visible for reference.')
                    ->schema([
                        self::linesRepeater()->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected static function headerFields(): array
    {
        return [
            Placeholder::make('po_number_display')
                ->label('PO No.')
                ->content(fn (?PurchaseOrder $record): string => filled($record?->number) ? (string) $record->number : '—')
                ->visible(fn (?PurchaseOrder $record): bool => filled($record?->number)),
            DatePicker::make('po_date')
                ->label('PO date')
                ->default(fn (): string => now()->toDateString())
                ->required()
                ->disabled()
                ->dehydrated(),
            TextInput::make('supplier_name')
                ->label('Supplier')
                ->required()
                ->datalist(fn (): array => Supplier::nameSuggestions())
                ->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                    if (blank($state)) {
                        return;
                    }

                    $supplier = Supplier::query()->where('name', trim($state))->first();
                    if ($supplier === null) {
                        return;
                    }

                    $set('supplier_id', $supplier->id);
                    if (blank($get('supplier_tin')) && filled($supplier->tin)) {
                        $set('supplier_tin', $supplier->tin);
                    }

                    $defaultAddress = $supplier->addresses()->orderByDesc('is_default')->value('address');
                    if (blank($get('supplier_address')) && filled($defaultAddress)) {
                        $set('supplier_address', $defaultAddress);
                    }
                })
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            TextInput::make('supplier_address')
                ->label('Supplier address')
                ->required()
                ->datalist(function (Get $get): array {
                    $supplierId = $get('supplier_id');
                    if (blank($supplierId) && filled($get('supplier_name'))) {
                        $supplierId = Supplier::query()->where('name', trim((string) $get('supplier_name')))->value('id');
                    }

                    return SupplierAddress::suggestionsForSupplier($supplierId ? (int) $supplierId : null);
                })
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            TextInput::make('supplier_tin')
                ->label('TIN')
                ->rule('regex:/^[0-9]+$/')
                ->extraInputAttributes(['inputmode' => 'numeric', 'pattern' => '[0-9]*'])
                ->dehydrateStateUsing(fn (?string $state): ?string => Supplier::normalizeTin($state))
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            Select::make('mode_of_procurement')
                ->label('Mode of procurement')
                ->options(ModeOfProcurementOptions::options())
                ->required()
                ->searchable()
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            TextInput::make('place_of_delivery')
                ->label('Place of delivery')
                ->default(fn (): ?string => app(SupplyOfficeResolver::class)->resolveOfficeName())
                ->required()
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            TextInput::make('delivery_term')
                ->label('Delivery term')
                ->placeholder('FOB Destination or FOB Shipping Point')
                ->datalist(ModeOfProcurementOptions::deliveryTermSuggestions())
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            DatePicker::make('date_of_delivery')
                ->label('Date of delivery')
                ->minDate(fn (Get $get): ?\Illuminate\Support\Carbon => filled($get('po_date'))
                    ? \Illuminate\Support\Carbon::parse((string) $get('po_date'))->addDay()
                    : now()->addDay())
                ->rule(fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get): void {
                    if (blank($value) || blank($get('po_date'))) {
                        return;
                    }

                    $delivery = \Illuminate\Support\Carbon::parse((string) $value)->startOfDay();
                    $poDate = \Illuminate\Support\Carbon::parse((string) $get('po_date'))->startOfDay();

                    if ($delivery->lessThanOrEqualTo($poDate)) {
                        $fail('Date of delivery must be after the PO date.');
                    }
                })
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            TextInput::make('payment_term')
                ->label('Payment term')
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            Textarea::make('technical_specifications')
                ->label('Technical specification / Remarks')
                ->required()
                ->rows(3)
                ->placeholder('Technical Specification')
                ->helperText('If the items have no technical specification, enter N/A.')
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Exported on a separate PDF page with the Conforme signature block.')
                ->columnSpanFull()
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            \Filament\Forms\Components\Hidden::make('supplier_id')->dehydrated(),
        ];
    }

    protected static function linesRepeater(): Repeater
    {
        return Repeater::make('lines')
            ->relationship()
            ->hiddenLabel()
            ->extraAttributes(['class' => 'owwa-acquisition-lines-repeater fi-fixed-positioning-context'])
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->table([
                TableColumn::make('Order')->width('7%'),
                TableColumn::make('Item')->width('18%'),
                TableColumn::make('Stock No.')->width('12%'),
                TableColumn::make('Description')->width('16%'),
                TableColumn::make('Unit')->width('8%'),
                TableColumn::make('PR qty')->width('8%'),
                TableColumn::make('PO qty')->markAsRequired()->width('8%'),
                TableColumn::make('Unit cost')->markAsRequired()->width('12%'),
                TableColumn::make('Total')->width('11%'),
            ])
            ->compact()
            ->schema([
                Checkbox::make('is_ordered')
                    ->hiddenLabel()
                    ->live()
                    ->disabled(fn (mixed $record): bool => ! self::isEditable($record))
                    ->dehydrated(),
                Placeholder::make('item_name')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => \App\Models\Item::query()->whereKey($get('item_id'))->value('name') ?? '—'),
                Placeholder::make('stock_no')
                    ->hiddenLabel()
                    ->content(function (Get $get): HtmlString {
                        $item = \App\Models\Item::query()->with(['category', 'uacsObjectCode'])->find($get('item_id'));
                        if ($item === null) {
                            return new HtmlString('<span class="owwa-cell-muted">—</span>');
                        }

                        $identifier = app(\App\Services\CatalogAssetNumberService::class)->catalogIdentifierForItem($item);

                        return new HtmlString(
                            '<span style="display:block;word-break:break-all;font-size:0.8125rem;">'
                            .e((string) ($identifier ?: '—'))
                            .'</span>'
                        );
                    }),
                Placeholder::make('description_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => (string) ($get('description') ?: '—')),
                Placeholder::make('unit_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => (string) ($get('unit') ?: '—')),
                Placeholder::make('pr_quantity_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => (string) ($get('pr_quantity') ?? '—')),
                TextInput::make('po_quantity')
                    ->hiddenLabel()
                    ->numeric()
                    ->minValue(0)
                    ->required(fn (Get $get): bool => (bool) $get('is_ordered'))
                    ->rule(fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get): void {
                        if (! $get('is_ordered')) {
                            return;
                        }

                        $prQty = (int) ($get('pr_quantity') ?? 0);
                        if ((int) $value < 1 || (int) $value > $prQty) {
                            $fail('PO quantity must be between 1 and PR quantity.');
                        }
                    })
                    ->disabled(fn (Get $get, mixed $record): bool => ! self::isEditable($record) || ! $get('is_ordered'))
                    ->dehydrated()
                    ->live(onBlur: true)
                    ->extraInputAttributes(['class' => 'owwa-acquisition-line-qty', 'inputmode' => 'numeric']),
                TextInput::make('unit_cost')
                    ->hiddenLabel()
                    ->numeric()
                    ->prefix('₱')
                    ->required(fn (Get $get): bool => (bool) $get('is_ordered'))
                    ->disabled(fn (Get $get, mixed $record): bool => ! self::isEditable($record) || ! $get('is_ordered'))
                    ->dehydrated()
                    ->live(onBlur: true)
                    ->extraInputAttributes(['class' => 'owwa-acquisition-line-unit-cost', 'inputmode' => 'decimal']),
                Placeholder::make('line_total')
                    ->hiddenLabel()
                    ->content(function (Get $get): string {
                        if (! $get('is_ordered')) {
                            return '—';
                        }

                        $qty = (int) ($get('po_quantity') ?? 0);
                        $cost = $get('unit_cost');
                        if ($qty <= 0 || blank($cost)) {
                            return '—';
                        }

                        return '₱'.number_format((float) $cost * $qty, 2);
                    }),
                \Filament\Forms\Components\Hidden::make('pr_quantity')->dehydrated(),
                \Filament\Forms\Components\Hidden::make('item_id')->dehydrated(),
                \Filament\Forms\Components\Hidden::make('description')->dehydrated(),
                \Filament\Forms\Components\Hidden::make('unit')->dehydrated(),
                \Filament\Forms\Components\Hidden::make('acquisition_paperwork_line_id')->dehydrated(),
                \Filament\Forms\Components\Hidden::make('sort_order')->dehydrated(),
            ]);
    }

    protected static function isEditable(mixed $record): bool
    {
        return self::resolvePurchaseOrder($record)?->isEditable() ?? false;
    }

    protected static function resolvePurchaseOrder(mixed $record): ?PurchaseOrder
    {
        if ($record instanceof PurchaseOrder) {
            return $record;
        }

        if ($record instanceof \App\Models\PurchaseOrderLine) {
            return $record->purchaseOrder
                ?? PurchaseOrder::query()->find($record->purchase_order_id);
        }

        return null;
    }
}
