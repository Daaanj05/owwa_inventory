<?php

namespace App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Schemas;

use App\Models\InspectionAcceptanceReport;
use App\Models\ProcurementSignatoryName;
use App\Support\SignatorySelect;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class InspectionAcceptanceReportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Linked documents')
                ->columns(2)
                ->schema([
                    Placeholder::make('po_number_display')
                        ->label('PO No.')
                        ->content(fn (?InspectionAcceptanceReport $record): string => $record?->purchaseOrder?->number ?: '—'),
                    Placeholder::make('pr_number_display')
                        ->label('PR No.')
                        ->content(fn (?InspectionAcceptanceReport $record): string => $record?->purchaseOrder?->purchaseRequest?->pr_number ?: '—'),
                ]),
            Section::make('Inspection & acceptance')
                ->description('Fill dates in order: Invoice → Inspection → Receive. Each must be today or earlier; each next date cannot be before the previous.')
                ->columns(2)
                ->schema(self::headerFields()),
            Section::make('Line items')
                ->description('PR quantity, PO quantity, and editable IAR quantity (defaults to PO quantity).')
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
            Placeholder::make('iar_number_display')
                ->label('IAR No.')
                ->content(fn (?InspectionAcceptanceReport $record): string => filled($record?->number) ? (string) $record->number : '—')
                ->visible(fn (?InspectionAcceptanceReport $record): bool => filled($record?->number)),
            DatePicker::make('iar_date')
                ->label('IAR date')
                ->default(fn (): string => now()->toDateString())
                ->required()
                ->live()
                ->disabled()
                ->dehydrated(),
            TextInput::make('invoice_number')
                ->label('Invoice No.')
                ->required()
                ->rule('regex:/^[A-Za-z0-9]+$/')
                ->disabled(fn (?InspectionAcceptanceReport $record): bool => ! self::isEditable($record)),
            DatePicker::make('invoice_date')
                ->label('Invoice date')
                ->required()
                ->live()
                ->maxDate(now())
                ->helperText('Date printed on the supplier invoice. Today or earlier.')
                ->rule(fn (Get $get): \Closure => self::notFutureRule('Invoice date'))
                ->rule(fn (Get $get): \Closure => self::onOrBeforeRule($get, 'date_inspected', 'Invoice date', 'inspection date'))
                ->rule(fn (Get $get): \Closure => self::onOrBeforeRule($get, 'date_received', 'Invoice date', 'receive date'))
                ->disabled(fn (?InspectionAcceptanceReport $record): bool => ! self::isEditable($record)),
            DatePicker::make('date_inspected')
                ->label('Inspection Date')
                ->required()
                ->live()
                ->minDate(fn (Get $get): ?Carbon => self::parseDate($get('invoice_date')))
                ->maxDate(now())
                ->helperText('On or after invoice date, and today or earlier.')
                ->rule(fn (Get $get): \Closure => self::notFutureRule('Inspection date'))
                ->rule(fn (Get $get): \Closure => self::onOrAfterRule($get, 'invoice_date', 'Inspection date', 'invoice date'))
                ->rule(fn (Get $get): \Closure => self::onOrBeforeRule($get, 'date_received', 'Inspection date', 'receive date'))
                ->disabled(fn (?InspectionAcceptanceReport $record): bool => ! self::isEditable($record)),
            DatePicker::make('date_received')
                ->label('Receive Date')
                ->required()
                ->live()
                ->minDate(fn (Get $get): ?Carbon => self::parseDate($get('date_inspected')))
                ->maxDate(now())
                ->helperText('On or after inspection date, and today or earlier. Use a past date if recording late.')
                ->rule(fn (Get $get): \Closure => self::notFutureRule('Receive Date'))
                ->rule(fn (Get $get): \Closure => self::onOrAfterRule($get, 'date_inspected', 'Receive Date', 'inspection date'))
                ->disabled(fn (?InspectionAcceptanceReport $record): bool => ! self::isEditable($record)),
            SignatorySelect::make('inspection_officer_name', ProcurementSignatoryName::ROLE_INSPECTION_OFFICER)
                ->label('Inspection officer')
                ->required()
                ->disabled(fn (?InspectionAcceptanceReport $record): bool => ! self::isEditable($record)),
            SignatorySelect::make('custodian_name', ProcurementSignatoryName::ROLE_CUSTODIAN)
                ->label('Supply and/or Property Custodian')
                ->required()
                ->disabled(fn (?InspectionAcceptanceReport $record): bool => ! self::isEditable($record)),
        ];
    }

    protected static function linesRepeater(): Repeater
    {
        return Repeater::make('lines')
            ->relationship()
            ->hiddenLabel()
            ->extraAttributes(['class' => 'owwa-acquisition-lines-repeater owwa-iar-lines-repeater fi-fixed-positioning-context'])
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->table([
                TableColumn::make('Item')->width('20%'),
                TableColumn::make('Stock No.')->width('12%'),
                TableColumn::make('Unit')->width('8%'),
                TableColumn::make('Requested Qty')->width('10%'),
                TableColumn::make('Ordered Qty')->width('10%'),
                TableColumn::make('Received Qty')->markAsRequired()->width('12%'),
                TableColumn::make('Unit cost')->width('14%'),
                TableColumn::make('Amount')->width('14%'),
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
                        $identifier = $item
                            ? app(\App\Services\CatalogAssetNumberService::class)->catalogIdentifierForItem($item)
                            : null;

                        return new HtmlString(
                            '<span class="owwa-iar-stock-no">'
                            .e((string) ($identifier ?: '—'))
                            .'</span>'
                        );
                    }),
                Placeholder::make('unit_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => (string) ($get('unit') ?: '—')),
                Placeholder::make('pr_qty_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => (string) ($get('pr_quantity') ?? '—')),
                Placeholder::make('po_qty_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => (string) ($get('po_quantity') ?? '—')),
                TextInput::make('iar_quantity')
                    ->hiddenLabel()
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->rule(fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get): void {
                        $poQty = (int) ($get('po_quantity') ?? 0);
                        if ((int) $value < 0 || (int) $value > $poQty) {
                            $fail('Max '.$poQty);
                        }
                    })
                    ->disabled(fn (mixed $record): bool => ! self::isEditable($record))
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (mixed $state, Get $get): void {
                        $poQty = (int) ($get('po_quantity') ?? 0);
                        if ($poQty < 0 || blank($state)) {
                            return;
                        }

                        $qty = (int) $state;
                        if ($qty >= 0 && $qty <= $poQty) {
                            return;
                        }

                        Notification::make()
                            ->danger()
                            ->title('Received Qty must be between 0 and Ordered Qty ('.$poQty.')')
                            ->send();
                    })
                    ->extraInputAttributes(['class' => 'owwa-acquisition-line-qty', 'inputmode' => 'numeric']),
                Placeholder::make('unit_cost_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => blank($get('unit_cost'))
                        ? '—'
                        : '₱'.number_format((float) $get('unit_cost'), 2)),
                Placeholder::make('amount_display')
                    ->hiddenLabel()
                    ->extraAttributes(['class' => 'owwa-acquisition-line-total'])
                    ->content(function (Get $get): string {
                        $qty = (int) ($get('iar_quantity') ?? 0);
                        $cost = $get('unit_cost');
                        if ($qty <= 0 || blank($cost)) {
                            return '—';
                        }

                        return '₱'.number_format((float) $cost * $qty, 2);
                    }),
                Hidden::make('pr_quantity')->dehydrated(),
                Hidden::make('po_quantity')->dehydrated(),
                Hidden::make('unit_cost')->dehydrated(),
                Hidden::make('item_id')->dehydrated(),
                Hidden::make('description')->dehydrated(),
                Hidden::make('unit')->dehydrated(),
                Hidden::make('purchase_order_line_id')->dehydrated(),
                Hidden::make('acquisition_paperwork_line_id')->dehydrated(),
                Hidden::make('sort_order')->dehydrated(),
            ]);
    }

    protected static function parseDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        return Carbon::parse((string) $value)->startOfDay();
    }

    protected static function notFutureRule(string $label): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($label): void {
            if (blank($value)) {
                return;
            }

            if (Carbon::parse((string) $value)->startOfDay()->isFuture()) {
                $fail("{$label} must be today or earlier.");
            }
        };
    }

    protected static function onOrBeforeRule(Get $get, string $otherField, string $label, string $otherLabel): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($get, $otherField, $label, $otherLabel): void {
            $date = self::parseDate($value);
            $other = self::parseDate($get($otherField));
            if ($date === null || $other === null) {
                return;
            }

            if ($date->gt($other)) {
                $fail("{$label} must be on or before {$otherLabel}.");
            }
        };
    }

    protected static function onOrAfterRule(Get $get, string $otherField, string $label, string $otherLabel): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($get, $otherField, $label, $otherLabel): void {
            $date = self::parseDate($value);
            $other = self::parseDate($get($otherField));
            if ($date === null || $other === null) {
                return;
            }

            if ($date->lt($other)) {
                $fail("{$label} must be on or after {$otherLabel}.");
            }
        };
    }

    protected static function isEditable(mixed $record): bool
    {
        return self::resolveReport($record)?->isEditable() ?? false;
    }

    protected static function resolveReport(mixed $record): ?InspectionAcceptanceReport
    {
        if ($record instanceof InspectionAcceptanceReport) {
            return $record;
        }

        if ($record instanceof \App\Models\InspectionAcceptanceReportLine) {
            return $record->inspectionAcceptanceReport
                ?? InspectionAcceptanceReport::query()->find($record->inspection_acceptance_report_id);
        }

        return null;
    }
}
