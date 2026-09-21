<?php

namespace App\Filament\Resources\Acquisitions\PurchaseOrders\Schemas;

use App\Models\DeliveryTerm;
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
                Section::make('Line items')
                    ->description('Set quantities and unit costs for each PR line to include on this PO.')
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
            DatePicker::make('po_date')
                ->label('PO date')
                ->default(fn (): string => now()->toDateString())
                ->required()
                ->hidden()
                ->dehydrated(),
            TextInput::make('supplier_name')
                ->required()
                ->dehydrated()
                ->dehydrateStateUsing(function (mixed $state, Get $get): ?string {
                    if (filled($state)) {
                        return trim((string) $state);
                    }

                    $supplierId = $get('supplier_id');
                    if (blank($supplierId)) {
                        return null;
                    }

                    return Supplier::query()->whereKey((int) $supplierId)->value('name');
                })
                ->hidden(),
            Select::make('supplier_id')
                ->label('Supplier')
                ->options(function (Get $get): array {
                    $options = Supplier::query()
                        ->active()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all();

                    $currentId = $get('supplier_id');
                    if (filled($currentId) && ! array_key_exists((int) $currentId, $options)) {
                        $name = Supplier::query()->whereKey((int) $currentId)->value('name');
                        if (filled($name)) {
                            $options[(int) $currentId] = $name;
                        }
                    }

                    return $options;
                })
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    if (blank($state)) {
                        $set('supplier_name', null);
                        $set('supplier_tin', null);
                        $set('supplier_address', null);

                        return;
                    }

                    $supplier = Supplier::query()->with('addresses')->find((int) $state);
                    if ($supplier === null) {
                        return;
                    }

                    $set('supplier_name', $supplier->name);
                    $set('supplier_tin', $supplier->tin);
                    $defaultAddress = $supplier->addresses->sortByDesc('is_default')->first()?->address;
                    $set('supplier_address', $defaultAddress);
                })
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            Select::make('mode_of_procurement')
                ->label('Mode of procurement')
                ->options(ModeOfProcurementOptions::options())
                ->required()
                ->searchable()
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            TextInput::make('supplier_tin')
                ->label('TIN')
                ->rule('regex:/^[0-9]+$/')
                ->extraInputAttributes(['inputmode' => 'numeric', 'pattern' => '[0-9]*'])
                ->dehydrateStateUsing(fn (?string $state): ?string => Supplier::normalizeTin($state))
                ->disabled()
                ->dehydrated()
                ->helperText('Filled from the selected supplier.'),
            TextInput::make('place_of_delivery')
                ->label('Place of delivery')
                ->default(fn (): ?string => app(SupplyOfficeResolver::class)->resolveOfficeName())
                ->required()
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            Select::make('supplier_address')
                ->label('Supplier address')
                ->options(function (Get $get): array {
                    $supplierId = $get('supplier_id');
                    if (blank($supplierId) && filled($get('supplier_name'))) {
                        $supplierId = Supplier::query()->where('name', trim((string) $get('supplier_name')))->value('id');
                    }

                    return collect(SupplierAddress::suggestionsForSupplier($supplierId ? (int) $supplierId : null))
                        ->mapWithKeys(fn (string $address): array => [$address => $address])
                        ->all();
                })
                ->searchable()
                ->required()
                ->columnSpanFull()
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            Select::make('delivery_term')
                ->label('Delivery term')
                ->options(fn (Get $get): array => DeliveryTerm::optionsIncluding($get('delivery_term')))
                ->searchable()
                ->preload()
                ->disabled(fn (?PurchaseOrder $record): bool => ! self::isEditable($record)),
            DatePicker::make('date_of_delivery')
                ->label('Date of delivery')
                ->required()
                ->native(false)
                ->displayFormat('Y-m-d')
                ->prefixIcon(Heroicon::Calendar)
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
                TableColumn::make('Item')->width('18%'),
                TableColumn::make('Stock No.')->width('14%'),
                TableColumn::make('Description')->width('16%'),
                TableColumn::make('Unit')->width('8%'),
                TableColumn::make('Requested Qty')->width('8%'),
                TableColumn::make('Ordered Qty')->markAsRequired()->width('8%'),
                TableColumn::make('Unit cost')->markAsRequired()->width('14%'),
                TableColumn::make('Total Amount')->width('14%'),
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
                            '<span class="owwa-po-stock-no">'
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
                    ->placeholder('Add Unit Cost')
                    ->minValue(0.01)
                    ->rule('gt:0')
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
                        if ($qty <= 0 || blank($cost) || (float) $cost <= 0) {
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
