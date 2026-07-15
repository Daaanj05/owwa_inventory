<?php

namespace App\Filament\Resources\Distributions\Schemas;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\User;
use App\Services\OfficeDistributionBalanceService;
use App\Support\EmployeeRequisitionStatus;
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

class DistributionForm
{
    public static function configure(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->heading(null)
                    ->compact()
                    ->columnSpanFull()
                    ->columns(2)
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
                            ->selectablePlaceholder(false)
                            ->dehydrated(false)
                            ->afterStateUpdated(fn (Set $set): mixed => $set('item_id', null))
                            ->afterStateHydrated(function (Select $component, $state, $record): void {
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
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->selectablePlaceholder(false)
                            ->disabled(fn (Get $get): bool => blank($get('item_category_id')))
                            ->placeholder(fn (Get $get): string => blank($get('item_category_id'))
                                ? 'Select a category first'
                                : 'Select an item')
                            ->afterStateUpdated(function (Get $get, Set $set) use ($user): void {
                                $set('requisition_id', null);

                                if (! $get('item_id') || ! ($user?->office_id ?? null)) {
                                    return;
                                }

                                $available = app(OfficeDistributionBalanceService::class)->availableQuantity(
                                    (int) $get('item_id'),
                                    (int) $user->office_id,
                                );

                                if ($available <= 0) {
                                    return;
                                }

                                $currentQty = (int) ($get('quantity') ?? 0);
                                if ($currentQty <= 0 || $currentQty > $available) {
                                    $set('quantity', $available);
                                }
                            }),
                        Placeholder::make('available_from_sc')
                            ->label('Available from SC issuance')
                            ->content(function (Get $get) use ($user): string {
                                $itemId = (int) ($get('item_id') ?? 0);
                                $officeId = (int) ($user?->office_id ?? 0);

                                if ($itemId <= 0 || $officeId <= 0) {
                                    return 'Select an item to see how many units SC issued to your office that are not yet distributed.';
                                }

                                $service = app(OfficeDistributionBalanceService::class);
                                $available = $service->availableQuantity($itemId, $officeId);
                                $issued = $service->issuedQuantity($itemId, $officeId);
                                $distributed = $service->distributedQuantity($itemId, $officeId);

                                return "{$available} available ({$issued} issued − {$distributed} already distributed)";
                            })
                            ->columnSpanFull(),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->rules([
                                function (Get $get) use ($user): \Closure {
                                    return function (string $attribute, mixed $value, \Closure $fail) use ($get, $user): void {
                                        $itemId = (int) ($get('item_id') ?? 0);
                                        $officeId = (int) ($user?->office_id ?? 0);
                                        $quantity = (int) $value;

                                        if ($itemId <= 0 || $officeId <= 0 || $quantity <= 0) {
                                            return;
                                        }

                                        $available = app(OfficeDistributionBalanceService::class)->availableQuantity($itemId, $officeId);

                                        if ($quantity > $available) {
                                            $fail("Only {$available} unit(s) remain from SC issuance for this item.");
                                        }
                                    };
                                },
                            ]),
                        Select::make('distributed_to')
                            ->label('Distribute to (Employee)')
                            ->options(function () use ($user): array {
                                $query = User::query()->where('role', User::ROLE_EMPLOYEE);

                                if ($user?->office_id) {
                                    $query->where('office_id', $user->office_id);
                                }

                                if ($user?->department_id) {
                                    $query->where('department_id', $user->department_id);
                                }

                                return $query->orderBy('name')->pluck('name', 'id')->toArray();
                            })
                            ->required()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('requisition_id', null)),
                        Select::make('requisition_id')
                            ->label('Employee requisition')
                            ->options(fn (Get $get): array => self::employeeRequisitionOptions(
                                $get('distributed_to') ? (int) $get('distributed_to') : null,
                                $get('item_id') ? (int) $get('item_id') : null,
                            ))
                            ->searchable()
                            ->live()
                            ->placeholder('Auto-match if left blank')
                            ->helperText('Optional. Open accepted employee requests for this employee and item (not yet closed). Leave blank to auto-match the latest with remaining endorsed qty.'),
                        DatePicker::make('distribution_date')
                            ->label('Date')
                            ->required()
                            ->default(now()),
                        Textarea::make('remarks')
                            ->columnSpanFull()
                            ->rows(2),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function employeeRequisitionOptions(?int $employeeId, ?int $itemId): array
    {
        if (! $employeeId || ! $itemId) {
            return [];
        }

        return Requisition::query()
            ->where('requested_by', $employeeId)
            ->where('status', Requisition::STATUS_ACCEPTED)
            ->whereNull('closed_at')
            ->whereHas('requestedBy', fn ($query) => $query->where('role', User::ROLE_EMPLOYEE))
            ->whereHas('items', fn ($query) => $query->where('item_id', $itemId))
            ->latest('created_at')
            ->get()
            ->filter(fn (Requisition $requisition): bool => EmployeeRequisitionStatus::remainingToFulfillForItem($requisition, $itemId) > 0)
            ->mapWithKeys(function (Requisition $requisition) use ($itemId): array {
                $remaining = EmployeeRequisitionStatus::remainingToFulfillForItem($requisition, $itemId);
                $target = EmployeeRequisitionStatus::fulfillmentTargetForItem($requisition, $itemId);
                $ref = $requisition->transaction_number ?? "#{$requisition->id}";
                $purpose = $requisition->purpose ?: 'No purpose';

                return [
                    $requisition->id => "{$ref} — {$purpose} (remaining {$remaining} of {$target})",
                ];
            })
            ->all();
    }
}
