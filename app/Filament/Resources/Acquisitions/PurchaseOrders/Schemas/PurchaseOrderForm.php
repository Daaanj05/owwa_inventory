<?php

namespace App\Filament\Resources\Acquisitions\PurchaseOrders\Schemas;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierAddress;
use App\Support\ModeOfProcurementOptions;
use App\Support\SupplyOfficeResolver;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Purchase request')
                    ->description('Linked approved PR.')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('pr_number_display')
                            ->label('PR No.')
                            ->content(fn (?PurchaseOrder $record): string => $record?->purchaseRequest?->pr_number ?: '—'),
                    ]),
                Section::make('Line items')
                    ->description('Set quantities and unit costs for each PR line to include on this PO.')
                    ->schema([
                        self::linesRepeater()->columnSpanFull(),
                        Placeholder::make('grand_total_amount')
                            ->label('Total Amount')
                            ->content(function (Get $get): string {
                                $total = collect($get('lines') ?? [])
                                    ->sum(function (mixed $line): float {
                                        if (! is_array($line)) {
                                            return 0.0;
                                        }

                                        $qty = (int) ($line['po_quantity'] ?? 0);
                                        $cost = $line['unit_cost'] ?? null;

                                        if ($qty <= 0 || blank($cost)) {
                                            return 0.0;
                                        }

                                        return (float) $cost * $qty;
                                    });

                                return $total > 0
                                    ? '₱'.number_format($total, 2)
                                    : '—';
                            })
                            ->extraAttributes(['class' => 'owwa-po-grand-total']),
                    ]),
                Section::make('Purpose and purchase order details')
                    ->description('Purpose from the PR, then supplier and delivery details. Save to get a PO No., export for signature, then mark Approved.')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('pr_purpose_display')
                            ->label('Purpose')
                            ->content(fn (?PurchaseOrder $record): string => $record?->purchaseRequest?->purpose ?: '—')
                            ->columnSpanFull(),
                        ...self::headerFields(),
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
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Assigned automatically when the purchase order is saved.')
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
                ->required()
                ->native(false)
                ->displayFormat('Y-m-d')
                ->minDate(fn (Get $get): Carbon => self::minDeliveryDate($get('po_date')))
                ->maxDate(fn (): Carbon => now()->addYears(5)->startOfDay())
                ->rule(fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get): void {
                    if (blank($value)) {
                        return;
                    }

                    try {
                        $delivery = Carbon::parse((string) $value)->startOfDay();
                    } catch (\Throwable) {
                        $fail('Enter a valid date of delivery.');

                        return;
                    }

                    if ($delivery->year < now()->year || $delivery->year > now()->year + 5) {
                        $fail('Date of delivery year must be between '.now()->year.' and '.(now()->year + 5).'.');

                        return;
                    }

                    $minDate = self::minDeliveryDate($get('po_date'));
                    if ($delivery->lt($minDate)) {
                        $fail('Date of delivery cannot be in the past and must be after the PO date.');
                    }
                })
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            TextInput::make('payment_term')
                ->label('Payment term')
                ->required()
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

    protected static function minDeliveryDate(mixed $poDate): Carbon
    {
        $afterPo = filled($poDate)
            ? Carbon::parse((string) $poDate)->startOfDay()->addDay()
            : now()->startOfDay()->addDay();

        $today = now()->startOfDay();

        return $afterPo->greaterThan($today) ? $afterPo : $today;
    }

    protected static function linesRepeater(): Repeater
    {
        return Repeater::make('lines')
            ->relationship()
            ->hiddenLabel()
            ->extraAttributes(['class' => 'owwa-acquisition-lines-repeater owwa-po-lines-repeater fi-fixed-positioning-context'])
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->table([
                TableColumn::make('Item')->width('20%'),
                TableColumn::make('Stock No.')->width('12%'),
                TableColumn::make('Description')->width('18%'),
                TableColumn::make('Unit')->width('8%'),
                TableColumn::make('Requested Qty')->width('8%'),
                TableColumn::make('Ordered Qty')->markAsRequired()->width('8%'),
                TableColumn::make('Unit cost')->markAsRequired()->width('14%'),
                TableColumn::make('Total Amount')->width('12%'),
            ])
            ->compact()
            ->schema([
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
                    ->required()
                    ->live(onBlur: true)
                    ->rule(fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get): void {
                        $prQty = (int) ($get('pr_quantity') ?? 0);
                        if ((int) $value < 1 || (int) $value > $prQty) {
                            $fail('Max '.$prQty);
                        }
                    })
                    ->disabled(fn (mixed $record): bool => ! self::isEditable($record))
                    ->dehydrated()
                    ->afterStateUpdated(function (mixed $state, Get $get): void {
                        $prQty = (int) ($get('pr_quantity') ?? 0);
                        if ($prQty < 1 || blank($state)) {
                            return;
                        }

                        $qty = (int) $state;
                        if ($qty >= 1 && $qty <= $prQty) {
                            return;
                        }

                        Notification::make()
                            ->danger()
                            ->title('Ordered Qty must be between 1 and Requested Qty ('.$prQty.')')
                            ->send();
                    })
                    ->extraInputAttributes(['class' => 'owwa-acquisition-line-qty', 'inputmode' => 'numeric']),
                TextInput::make('unit_cost')
                    ->hiddenLabel()
                    ->numeric()
                    ->prefix('₱')
                    ->required()
                    ->disabled(fn (mixed $record): bool => ! self::isEditable($record))
                    ->dehydrated()
                    ->live()
                    ->extraInputAttributes(['class' => 'owwa-acquisition-line-unit-cost', 'inputmode' => 'decimal']),
                Placeholder::make('line_total')
                    ->hiddenLabel()
                    ->extraAttributes(['class' => 'owwa-acquisition-line-total'])
                    ->content(function (Get $get): string {
                        $qty = (int) ($get('po_quantity') ?? 0);
                        $cost = $get('unit_cost');
                        if ($qty <= 0 || blank($cost)) {
                            return '—';
                        }

                        return '₱'.number_format((float) $cost * $qty, 2);
                    }),
                \Filament\Forms\Components\Hidden::make('is_ordered')
                    ->default(true)
                    ->dehydrated(),
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
