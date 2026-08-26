<?php

namespace App\Filament\Resources\Items\Support;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\StockOpeningBalance;
use App\Models\User;
use App\Services\OpeningBalanceService;
use App\Support\PpeValueCategory;
use App\Support\SemiExpendableValueCategory;
use App\Support\SupplyOfficeResolver;
use App\Support\WhitelistedTextInput;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * Whitelist accepted input for starting stock quantity and unit cost.
 */
class ItemOpeningStockFields
{
    public const QUANTITY_KEY = 'opening_quantity';

    public const UNIT_COST_KEY = 'opening_unit_cost';

    /**
     * @return array{office_id: ?int, quantity: ?int, unit_cost: ?float}
     */
    public static function extract(array &$data): array
    {
        $opening = [
            'office_id' => self::resolveRegionalOfficeId(),
            'quantity' => filled($data[self::QUANTITY_KEY] ?? null) ? (int) $data[self::QUANTITY_KEY] : null,
            'unit_cost' => filled($data[self::UNIT_COST_KEY] ?? null) ? (float) $data[self::UNIT_COST_KEY] : null,
        ];

        unset($data[self::QUANTITY_KEY], $data[self::UNIT_COST_KEY], $data['opening_office_id']);

        return $opening;
    }

    /**
     * @param  array{office_id: ?int, quantity: ?int, unit_cost: ?float}  $opening
     */
    public static function applyIfPresent(Item $item, array $opening, ?User $recordedBy = null): void
    {
        $quantity = (int) ($opening['quantity'] ?? 0);
        if ($quantity < 1) {
            return;
        }

        $officeId = $opening['office_id'] ?? self::resolveRegionalOfficeId();
        if ($officeId === null || $officeId < 1) {
            throw ValidationException::withMessages([
                self::QUANTITY_KEY => 'Regional supply office is not configured. Starting stock cannot be recorded.',
            ]);
        }

        app(OpeningBalanceService::class)->setOpeningStock(
            item: $item,
            officeId: (int) $officeId,
            quantity: $quantity,
            unitCost: $opening['unit_cost'] ?? null,
            recordedBy: $recordedBy,
        );
    }

    public static function resolveRegionalOfficeId(): ?int
    {
        return app(SupplyOfficeResolver::class)->resolve();
    }

    /**
     * One-time legacy backfill: no opening balance and no acquisition at the regional office.
     */
    public static function canSetStartingStock(Item $item, ?int $officeId = null): bool
    {
        if ($item->archived_at !== null) {
            return false;
        }

        $officeId ??= self::resolveRegionalOfficeId();
        if ($officeId === null || $officeId < 1) {
            return false;
        }

        if (StockOpeningBalance::query()
            ->where('item_id', $item->id)
            ->where('office_id', $officeId)
            ->exists()) {
            return false;
        }

        return ! Acquisition::query()
            ->where('item_id', $item->id)
            ->where('office_id', $officeId)
            ->exists();
    }

    /**
     * Modal fields for the one-time Set starting stock action (not create-form operation-gated).
     *
     * @return array<int, TextInput>
     */
    public static function actionFormFields(Item $item): array
    {
        $categoryId = $item->item_category_id;

        return [
            self::configureDigitsOnlyQuantity(
                TextInput::make(self::QUANTITY_KEY)
                    ->label('Starting quantity')
                    ->required()
                    ->helperText('Assigned to the regional supply office. Cannot be changed later.'),
            )->live(),
            self::configureDigitsOnlyUnitCost(
                TextInput::make(self::UNIT_COST_KEY)
                    ->label('Starting unit cost')
                    ->required(fn (Get $get): bool => self::requiresUnitCost($categoryId)
                        && filled($get(self::QUANTITY_KEY)))
                    ->visible(function (Get $get) use ($categoryId): bool {
                        if (self::requiresUnitCost($categoryId)) {
                            return true;
                        }

                        return self::categorySlug($categoryId) === 'consumables'
                            && filled($get(self::QUANTITY_KEY));
                    })
                    ->helperText(fn (): ?string => match (self::categorySlug($categoryId)) {
                        'ppe' => PpeValueCategory::minimumRuleSummary(),
                        'semi_expendable' => SemiExpendableValueCategory::tierRuleSummary(),
                        'consumables' => 'Optional. If blank, starting stock is stored at ₱0 cost. Later receipts use the acquisition unit cost.',
                        default => null,
                    }),
            ),
        ];
    }

    /**
     * Table / page action: set opening stock once for a legacy catalog item.
     */
    public static function makeSetStartingStockAction(): Action
    {
        return Action::make('setOpeningStock')
            ->label('Set starting stock')
            ->icon(Heroicon::ArchiveBoxArrowDown)
            ->color('gray')
            ->visible(function (?Item $record = null): bool {
                $user = Filament::auth()->user();
                if (! $user?->isSupplyCustodian()) {
                    return false;
                }

                if ($record === null) {
                    return true;
                }

                return self::canSetStartingStock($record);
            })
            ->modalHeading(fn (?Item $record): string => $record
                ? 'Set starting stock — '.$record->name
                : 'Set starting stock')
            ->modalDescription('One-time starting stock for the regional supply office. Use acquisitions for later receipts.')
            ->modalSubmitActionLabel('Save starting stock')
            ->schema(fn (Item $record): array => self::actionFormFields($record))
            ->action(function (Item $record, array $data): void {
                self::persistFromActionData($record, $data);
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function persistFromActionData(Item $item, array $data, ?User $recordedBy = null): void
    {
        if (! self::canSetStartingStock($item)) {
            throw ValidationException::withMessages([
                self::QUANTITY_KEY => 'Starting stock cannot be set for this item. It may already have an opening balance or acquisition history.',
            ]);
        }

        $opening = self::extract($data);
        $user = $recordedBy ?? Filament::auth()->user();
        self::applyIfPresent(
            $item,
            $opening,
            $user instanceof User ? $user : null,
        );

        Notification::make()
            ->title('Starting stock saved')
            ->body('Quantity was recorded for the regional supply office.')
            ->success()
            ->send();
    }

    /**
     * Fields embedded in the main create form (no separate section).
     *
     * @return array<int, TextInput>
     */
    public static function createFields(): array
    {
        return [
            self::configureDigitsOnlyQuantity(
                TextInput::make(self::QUANTITY_KEY)
                    ->label('Starting quantity')
                    ->visible(fn (string $operation): bool => $operation === 'create')
                    ->helperText('Optional starting stock. Assigned to the regional supply office. Cannot be changed later from the Items list.'),
            )->live(),
            self::configureDigitsOnlyUnitCost(
                TextInput::make(self::UNIT_COST_KEY)
                    ->label('Starting unit cost')
                    ->required(fn (Get $get): bool => self::requiresUnitCost($get('item_category_id'))
                        && filled($get(self::QUANTITY_KEY)))
                    ->visible(function (string $operation, Get $get): bool {
                        if ($operation !== 'create') {
                            return false;
                        }

                        // PPE / semi: always show (required when qty is set).
                        if (self::requiresUnitCost($get('item_category_id'))) {
                            return true;
                        }

                        // Consumables: show when starting quantity is entered (optional;
                        // otherwise unit cost usually comes from acquisitions).
                        return self::categorySlug($get('item_category_id')) === 'consumables'
                            && filled($get(self::QUANTITY_KEY));
                    })
                    ->helperText(fn (Get $get): ?string => match (self::categorySlug($get('item_category_id'))) {
                        'ppe' => PpeValueCategory::minimumRuleSummary(),
                        'semi_expendable' => SemiExpendableValueCategory::tierRuleSummary(),
                        'consumables' => 'Optional. If blank, starting stock is stored at ₱0 cost. Later receipts use the acquisition unit cost.',
                        default => null,
                    }),
            ),
        ];
    }

    public static function bulkQuantityField(): TextInput
    {
        return self::configureDigitsOnlyQuantity(
            TextInput::make(self::QUANTITY_KEY)
                ->hiddenLabel()
                ->placeholder('Qty'),
        );
    }

    public static function bulkUnitCostField(bool $requiredWhenQuantity = true): TextInput
    {
        return self::configureDigitsOnlyUnitCost(
            TextInput::make(self::UNIT_COST_KEY)
                ->hiddenLabel()
                ->placeholder('Cost')
                ->required(function (Get $get) use ($requiredWhenQuantity): bool {
                    if (! $requiredWhenQuantity) {
                        return false;
                    }

                    return filled($get(self::QUANTITY_KEY));
                }),
        );
    }

    /**
     * Confirmation modal shown after clicking Create (not on the form itself).
     *
     * Use with makeModalSubmitAction() / extraModalFooterActions() so Filament
     * prepareModalAction() attaches Livewire. The default modal submit button uses
     * form submit and skips confirmation entirely.
     */
    public static function applyCreateConfirmation(Action $submitAction, string $heading = 'Create this item?'): Action
    {
        $arguments = $submitAction->getArguments();

        return $submitAction
            ->submit(null)
            ->callParent(null)
            ->requiresConfirmation()
            ->modalHeading($heading)
            ->modalDescription('Do you confirm the details are correct? Starting stock cannot be changed later from the Items list.')
            ->modalSubmitActionLabel('Yes, create')
            ->overlayParentActions()
            ->action(function (Action $action) use ($arguments): void {
                $livewire = $action->getLivewire() ?? $action->getParentAction()?->getLivewire();

                if ($livewire === null) {
                    return;
                }

                // Drop the confirmation action; keep the parent create/bulk action mounted.
                $livewire->unmountAction(canCancelParentActions: false);

                $parent = $livewire->getMountedAction();

                if ($parent === null) {
                    return;
                }

                // Schema-resolved parent actions may not have Livewire set after remount cache churn.
                $parent->livewire($livewire);
                $parent->successRedirectUrl('');

                $livewire->callMountedAction($arguments);
                $action->halt();
            });
    }

    /**
     * Footer Create button that confirms before calling the parent modal action.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function confirmingSubmitAction(
        Action $parentAction,
        string $label = 'Create',
        string $heading = 'Create this item?',
        array $arguments = [],
    ): Action {
        $submit = $parentAction->makeModalSubmitAction('submit', $arguments)
            ->label($label)
            ->color(match ($parentAction->getColor()) {
                'gray' => 'primary',
                default => $parentAction->getColor(),
            });

        return self::applyCreateConfirmation($submit, $heading);
    }

    protected static function configureDigitsOnlyQuantity(TextInput $field): TextInput
    {
        return $field
            ->live(onBlur: true)
            ->rules(['nullable', 'integer', 'min:1'])
            ->inputMode('numeric')
            ->extraAlpineAttributes(WhitelistedTextInput::digitsOnlyAlpineAttributes())
            ->afterStateUpdated(function (Set $set, mixed $state): void {
                if ($state === null || $state === '') {
                    $set(self::QUANTITY_KEY, null);

                    return;
                }

                $digits = preg_replace('/\D+/', '', (string) $state) ?? '';
                $set(self::QUANTITY_KEY, $digits === '' ? null : $digits);
            });
    }

    protected static function configureDigitsOnlyUnitCost(TextInput $field): TextInput
    {
        return $field
            ->live(onBlur: true)
            ->rules(['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'])
            ->inputMode('decimal')
            ->extraAlpineAttributes(WhitelistedTextInput::decimalMoneyAlpineAttributes())
            ->afterStateUpdated(function (Set $set, mixed $state): void {
                if ($state === null || $state === '') {
                    $set(self::UNIT_COST_KEY, null);

                    return;
                }

                $cleaned = preg_replace('/[^0-9.]/', '', (string) $state) ?? '';
                if (substr_count($cleaned, '.') > 1) {
                    $parts = explode('.', $cleaned, 2);
                    $cleaned = $parts[0].'.'.str_replace('.', '', $parts[1] ?? '');
                }

                if (str_contains($cleaned, '.')) {
                    [$whole, $fraction] = array_pad(explode('.', $cleaned, 2), 2, '');
                    $cleaned = $whole.'.'.substr($fraction, 0, 2);
                }

                $set(self::UNIT_COST_KEY, $cleaned === '' || $cleaned === '.' ? null : $cleaned);
            });
    }

    protected static function requiresUnitCost(mixed $categoryId): bool
    {
        return in_array(self::categorySlug($categoryId), ['ppe', 'semi_expendable'], true);
    }

    protected static function categorySlug(mixed $categoryId): ?string
    {
        if (blank($categoryId)) {
            return null;
        }

        return ItemCategory::query()->find($categoryId)?->getTemplateSlug();
    }
}
