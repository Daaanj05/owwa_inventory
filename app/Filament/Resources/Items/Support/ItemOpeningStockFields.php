<?php

namespace App\Filament\Resources\Items\Support;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use App\Services\OpeningBalanceService;
use App\Support\PpeValueCategory;
use App\Support\SemiExpendableValueCategory;
use App\Support\SupplyOfficeResolver;
use App\Support\WhitelistedTextInput;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
            ),
            self::configureDigitsOnlyUnitCost(
                TextInput::make(self::UNIT_COST_KEY)
                    ->label('Starting unit cost')
                    ->required(fn (Get $get): bool => self::requiresUnitCost($get('item_category_id'))
                        && filled($get(self::QUANTITY_KEY)))
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'create'
                        && self::requiresUnitCost($get('item_category_id')))
                    ->helperText(fn (Get $get): ?string => match (self::categorySlug($get('item_category_id'))) {
                        'ppe' => PpeValueCategory::minimumRuleSummary(),
                        'semi_expendable' => SemiExpendableValueCategory::tierRuleSummary(),
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
