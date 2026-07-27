<?php

namespace App\Filament\Resources\Requisitions\Schemas;

use App\Models\Department;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Services\RequisitionCompileService;
use App\Services\RequisitionRestockStatusService;
use App\Support\InventoryCategoryOptions;
use App\Support\OwwaReferenceLabels;
use App\Support\SupplyOfficeResolver;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\GridDirection;
use Illuminate\Support\HtmlString;

class RequisitionForm
{
    public static function createModalDescription(): string
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();
        $supplyOfficeName = app(SupplyOfficeResolver::class)->resolveOfficeName() ?? 'regional supply office';

        if ($user?->isEmployee()) {
            return "Available shows how much stock is at {$supplyOfficeName} today. You can submit even when Available is 0 — your Unit Consolidator will review it. Purpose is required.";
        }

        if ($user?->isUnitConsolidator()) {
            return 'Select office and department first, then choose accepted employee requests to endorse.';
        }

        return 'Select a category on each line before choosing an item. Available shows stock at '.$supplyOfficeName.' (regional supply).';
    }

    public static function configure(Schema $schema): Schema
    {
        $scopeActive = fn ($query) => $query->active();
        /** @var User|null $user */
        $user = Filament::auth()->user();
        $isCustodian = $user?->isSupplyCustodian() ?? false;
        $isEmployee = $user?->isEmployee() ?? false;
        $isUnitConsolidator = $user?->isUnitConsolidator() ?? false;
        $needsOfficeSelection = $isEmployee && blank($user?->office_id);

        return $schema
            ->components([
                Section::make('Requisition details')
                    ->heading(fn (string $operation): ?string => $operation === 'edit' && $isEmployee ? null : 'Requisition details')
                    ->compact(fn (string $operation): bool => $operation === 'edit' && $isEmployee)
                    ->description(function (string $operation) use ($isCustodian, $isUnitConsolidator, $isEmployee): ?string {
                        if ($operation === 'edit' && $isEmployee) {
                            return null;
                        }

                        if ($isCustodian) {
                            return 'Manage this requisition.';
                        }

                        if ($isUnitConsolidator) {
                            return 'Choose office and department first.';
                        }

                        return 'Submit a request for inventory items. Purpose is required before submitting to your Unit Consolidator.';
                    })
                    ->extraAttributes(fn (string $operation): array => $operation === 'edit' && $isEmployee
                        ? ['class' => 'owwa-requisition-details-section--compact']
                        : [])
                    ->visible(fn (string $operation): bool => $isUnitConsolidator
                        || $isCustodian
                        || ($isEmployee && ($needsOfficeSelection || $operation === 'edit')))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('transaction_number')
                            ->label(OwwaReferenceLabels::employeeRequisitionTransaction())
                            ->disabled()
                            ->extraAttributes(['class' => 'owwa-requisition-transaction-no'])
                            ->visible(fn (string $operation, ?Requisition $record): bool => $operation === 'edit' && ($record?->isEmployeeRequest() ?? $isEmployee))
                            ->columnSpanFull(),
                        TextInput::make('reference_code')
                            ->label(OwwaReferenceLabels::requisition())
                            ->disabled()
                            ->visible(fn (string $operation, ?Requisition $record): bool => $operation === 'edit' && ! ($record?->isEmployeeRequest() ?? $isEmployee))
                            ->columnSpanFull(),
                        Select::make('office_id')
                            ->label('Office')
                            ->relationship(
                                'office',
                                'name',
                                function ($query) use ($scopeActive, $isUnitConsolidator, $user) {
                                    $query = $scopeActive($query);

                                    if ($isUnitConsolidator && $user instanceof User) {
                                        $officeIds = $user->assignedOfficeIds();

                                        if ($officeIds !== []) {
                                            $query->whereIn('id', $officeIds);
                                        }
                                    }

                                    return $query;
                                }
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->selectablePlaceholder(false)
                            ->default(function () use ($user, $isUnitConsolidator): ?int {
                                if (! $user instanceof User) {
                                    return null;
                                }

                                if ($isUnitConsolidator && $user->hasSingleOfficeAssignment()) {
                                    return $user->assignedOfficeIds()[0] ?? $user->office_id;
                                }

                                return $user->office_id;
                            })
                            ->dehydrated()
                            ->hidden(fn (): bool => $isEmployee && ! $needsOfficeSelection)
                            ->disabled(function () use ($isCustodian, $isUnitConsolidator, $user): bool {
                                if ($isCustodian || $isUnitConsolidator) {
                                    return false;
                                }

                                return filled($user?->office_id);
                            })
                            ->live()
                            ->afterStateUpdated(function (?int $state, Set $set, Get $get, mixed $old) use ($user, $isUnitConsolidator): void {
                                if (! $isUnitConsolidator) {
                                    return;
                                }

                                $officeChanged = (int) ($old ?? 0) !== (int) ($state ?? 0);

                                if ($officeChanged) {
                                    $set('source_requisition_ids', []);
                                    $set('endorsement_lines', []);
                                    $set('items', []);
                                }

                                if ($state === null || ! $user instanceof User) {
                                    if ($officeChanged) {
                                        $set('department_id', null);
                                    }

                                    return;
                                }

                                $allowedDepartmentIds = $user->assignedDepartmentIdsForOffice($state);

                                if ($user->hasSingleDepartmentAssignmentForOffice($state)) {
                                    $set('department_id', $allowedDepartmentIds[0] ?? null);

                                    return;
                                }

                                $currentDepartmentId = filled($get('department_id')) ? (int) $get('department_id') : null;

                                if ($currentDepartmentId === null || ! in_array($currentDepartmentId, $allowedDepartmentIds, true)) {
                                    $set('department_id', null);
                                }
                            }),
                        Select::make('department_id')
                            ->label('Department')
                            ->options(fn (Get $get): array => self::departmentOptionsForRequisition($get, $isUnitConsolidator, $user, $scopeActive))
                            ->searchable()
                            ->preload()
                            ->selectablePlaceholder(false)
                            ->required(fn (): bool => $isUnitConsolidator)
                            ->default(function (Get $get) use ($user, $isUnitConsolidator): ?int {
                                if (! $user instanceof User || ! $isUnitConsolidator) {
                                    return $user?->department_id;
                                }

                                $officeId = filled($get('office_id')) ? (int) $get('office_id') : (int) ($user->office_id ?? 0);

                                if ($officeId > 0 && $user->hasSingleDepartmentAssignmentForOffice($officeId)) {
                                    return $user->assignedDepartmentIdsForOffice($officeId)[0] ?? $user->department_id;
                                }

                                return $user->department_id;
                            })
                            ->dehydrated()
                            ->hidden(fn (): bool => $isEmployee && filled($user?->department_id))
                            ->disabled(function () use ($isCustodian, $isUnitConsolidator, $user): bool {
                                if ($isCustodian || $isUnitConsolidator) {
                                    return false;
                                }

                                return filled($user?->department_id);
                            })
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                                if ((int) ($old ?? 0) === (int) ($state ?? 0)) {
                                    return;
                                }

                                $set('source_requisition_ids', []);
                                $set('endorsement_lines', []);
                                $set('items', []);
                            }),
                        Select::make('status')
                            ->label('Status')
                            ->options([
                                Requisition::STATUS_PENDING => 'Pending',
                                Requisition::STATUS_ACCEPTED => 'Accepted',
                                Requisition::STATUS_REJECTED => 'Rejected',
                            ])
                            ->default(Requisition::STATUS_PENDING)
                            ->required()
                            ->visible(fn (): bool => $isCustodian),
                    ]),
                Section::make('Compile employee requests')
                    ->description('Select accepted (reviewed) employee requisitions. Endorsed quantities merge into the consolidated RIS below.')
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'create'
                        && $isUnitConsolidator
                        && filled($get('office_id'))
                        && filled($get('department_id')))
                    ->columnSpanFull()
                    ->schema([
                        Hidden::make('source_requisition_ids')
                            ->default([])
                            ->dehydrated(),
                        Placeholder::make('source_requisitions_summary')
                            ->label('Employee requisitions to include')
                            ->content(function (Get $get): HtmlString {
                                return self::selectedEmployeeRequisitionsSummaryHtml(
                                    $get('source_requisition_ids') ?? [],
                                );
                            })
                            ->helperText(function (Get $get): string {
                                $officeId = filled($get('office_id')) ? (int) $get('office_id') : null;
                                $departmentId = filled($get('department_id')) ? (int) $get('department_id') : null;

                                $officeName = $officeId ? Office::query()->find($officeId)?->name : null;
                                $departmentName = $departmentId ? Department::query()->find($departmentId)?->name : null;

                                if ($officeName && $departmentName) {
                                    return "Use Select requisitions to choose reviewed employee requests. Showing accepted requests for {$officeName} / {$departmentName} only.";
                                }

                                return 'Use Select requisitions to choose reviewed employee requests that have not yet been sent to the Supply Custodian.';
                            }),
                        SchemaActions::make([
                            Action::make('selectEmployeeRequisitions')
                                ->label(fn (Get $get): string => filled($get('source_requisition_ids'))
                                    ? 'Change selection'
                                    : 'Select requisitions')
                                ->icon('heroicon-o-queue-list')
                                ->color('primary')
                                ->modalHeading('Select employee requisitions')
                                ->modalDescription('Pick reviewed employee requests for the selected office and department.')
                                ->modalWidth('3xl')
                                ->modalSubmitActionLabel('Apply selection')
                                ->fillForm(fn (Get $get): array => [
                                    'picked_source_requisition_ids' => collect($get('source_requisition_ids') ?? [])
                                        ->map(fn ($id): int => (int) $id)
                                        ->filter(fn (int $id): bool => $id > 0)
                                        ->values()
                                        ->all(),
                                    'picker_office_id' => $get('office_id'),
                                    'picker_department_id' => $get('department_id'),
                                ])
                                ->schema([
                                    Hidden::make('picker_office_id'),
                                    Hidden::make('picker_department_id'),
                                    CheckboxList::make('picked_source_requisition_ids')
                                        ->hiddenLabel()
                                        ->searchable()
                                        ->bulkToggleable()
                                        ->columns(1)
                                        ->gridDirection(GridDirection::Row)
                                        ->extraAttributes([
                                            'class' => 'owwa-uc-employee-requisition-picker-list',
                                        ])
                                        ->options(function (Get $get) use ($user): array {
                                            if (! $user instanceof User) {
                                                return [];
                                            }

                                            $officeId = filled($get('picker_office_id')) ? (int) $get('picker_office_id') : null;
                                            $departmentId = filled($get('picker_department_id')) ? (int) $get('picker_department_id') : null;

                                            return app(RequisitionCompileService::class)->eligibleEmployeeRequisitionOptions(
                                                $user,
                                                $officeId,
                                                $departmentId,
                                            );
                                        }),
                                ])
                                ->action(function (array $data, Set $schemaSet): void {
                                    $selectedIds = collect($data['picked_source_requisition_ids'] ?? [])
                                        ->map(fn ($id): int => (int) $id)
                                        ->filter(fn (int $id): bool => $id > 0)
                                        ->unique()
                                        ->values()
                                        ->all();

                                    self::applySelectedSourceRequisitions($schemaSet, $selectedIds);
                                }),
                        ]),
                    ]),
                Section::make('Review & endorse employee requests')
                    ->description('Adjust endorsed quantities per employee line. Add remarks when endorsing less than requested.')
                    ->extraAttributes(['class' => 'owwa-uc-endorse-section'])
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'create'
                        && $isUnitConsolidator
                        && filled($get('source_requisition_ids')))
                    ->columnSpanFull()
                    ->schema([
                        self::endorsementLinesRepeater(),
                        Placeholder::make('consolidated_totals_summary')
                            ->hiddenLabel()
                            ->content(fn (Get $get): HtmlString => self::consolidatedTotalsSummaryHtml($get('items') ?? []))
                            ->columnSpanFull(),
                    ]),
                Section::make('Request Items')
                    ->extraAttributes(['class' => 'owwa-requisition-items-section'])
                    ->description(fn (): ?string => $isEmployee ? 'Select a category on each line before choosing an item.' : null)
                    ->visible(fn (): bool => ! $isCustodian)
                    ->hidden(fn (Get $get): bool => $isUnitConsolidator && filled($get('source_requisition_ids')))
                    ->columnSpanFull()
                    ->schema([
                        self::requestItemsRepeater($isCustodian, $isUnitConsolidator, $isEmployee),
                        Textarea::make('purpose')
                            ->label('Purpose')
                            ->required()
                            ->rows(2)
                            ->autosize()
                            ->columnSpanFull()
                            ->extraFieldWrapperAttributes(['class' => 'owwa-requisition-purpose-field-wrp'])
                            ->extraAttributes(['class' => 'owwa-requisition-purpose-field'])
                            ->extraAlpineAttributes(['@input' => 'resize()'])
                            ->visible(fn (string $operation): bool => $isEmployee && in_array($operation, ['create', 'edit'], true)),
                    ]),
                Section::make()
                    ->heading(null)
                    ->compact()
                    ->extraAttributes(['class' => 'owwa-uc-purpose-section'])
                    ->visible(fn (): bool => $isUnitConsolidator)
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('purpose')
                            ->label('Purpose')
                            ->rows(2)
                            ->required()
                            ->columnSpanFull()
                            ->extraFieldWrapperAttributes(['class' => 'owwa-uc-compile-purpose-wrp'])
                            ->extraAttributes(['class' => 'owwa-uc-compile-purpose-field']),
                    ]),
            ]);
    }

    /**
     * @param  array<int|string, mixed>  $items
     */
    /**
     * @param  list<int|string>|array<int, int|string>  $selectedIds
     */
    private static function applySelectedSourceRequisitions(Set $set, array $selectedIds): void
    {
        $selectedIds = collect($selectedIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $set('source_requisition_ids', $selectedIds);

        if ($selectedIds === []) {
            $set('endorsement_lines', []);
            $set('items', []);

            return;
        }

        $requisitions = Requisition::query()
            ->whereIn('id', $selectedIds)
            ->with(['items.item.category', 'requestedBy'])
            ->get();

        $compileService = app(RequisitionCompileService::class);
        $endorsementLines = $compileService->buildEndorsementLines($requisitions);
        $set('endorsement_lines', $endorsementLines);
        $set('items', $compileService->mergedLineItemsAsRepeaterState(
            $compileService->mergedLineItemsFromEndorsements($endorsementLines),
        ));
    }

    private static function selectedEmployeeRequisitionsSummaryHtml(array $selectedIds): HtmlString
    {
        $ids = collect($selectedIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return new HtmlString(
                '<p class="text-sm text-gray-500 dark:text-gray-400">No requisitions selected yet. Use <strong>Select requisitions</strong> to choose reviewed employee requests.</p>'
            );
        }

        $labels = Requisition::query()
            ->whereIn('id', $ids->all())
            ->with(['requestedBy'])
            ->withCount('items')
            ->get()
            ->sortBy(fn (Requisition $requisition): int => (int) array_search($requisition->id, $ids->all(), true))
            ->map(fn (Requisition $requisition): string => e(
                app(RequisitionCompileService::class)->employeeRequisitionOptionLabel($requisition)
            ))
            ->values();

        $count = $labels->count();
        $list = $labels
            ->map(fn (string $label): string => '<li>'.$label.'</li>')
            ->implode('');

        return new HtmlString(
            '<div class="owwa-uc-source-req-summary">'
            .'<p class="text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">'
            .$count.' '.($count === 1 ? 'requisition' : 'requisitions').' selected</p>'
            .'<ul class="text-sm text-gray-600 dark:text-gray-300 list-disc ps-5 space-y-0.5">'.$list.'</ul>'
            .'</div>'
        );
    }

    private static function consolidatedTotalsSummaryHtml(array $items): HtmlString
    {
        $rows = collect($items)
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['item_id'] ?? null))
            ->map(function (array $row): array {
                $item = Item::query()->with('category')->find((int) $row['item_id']);
                $available = '—';
                $itemId = (int) ($row['item_id'] ?? 0);
                $supplyOfficeId = app(SupplyOfficeResolver::class)->resolve();

                if ($itemId > 0 && $supplyOfficeId !== null) {
                    $available = (string) max(0, app(InventoryStockService::class)->getStock($itemId, $supplyOfficeId));
                }

                return [
                    'category' => $item?->category?->name ?? '—',
                    'item' => $item?->name ?? (string) ($row['item_name'] ?? 'Item'),
                    'available' => $available,
                    'requested' => (int) ($row['requested_total'] ?? $row['quantity'] ?? 0),
                    'endorsed' => (int) ($row['quantity'] ?? 0),
                    'sources' => (string) ($row['allocation_summary'] ?? '—'),
                ];
            })
            ->values();

        if ($rows->isEmpty()) {
            return new HtmlString('<p class="text-sm text-gray-500">No consolidated lines yet.</p>');
        }

        $body = $rows
            ->map(function (array $row): string {
                return sprintf(
                    '<tr><td>%s</td><td>%s</td><td class="text-right">%s</td><td class="text-right">%d</td><td class="text-right">%d</td><td>%s</td></tr>',
                    e($row['category']),
                    e($row['item']),
                    e($row['available']),
                    $row['requested'],
                    $row['endorsed'],
                    e($row['sources']),
                );
            })
            ->implode('');

        return new HtmlString(
            '<div class="owwa-consolidated-totals-summary">'
            .'<div class="owwa-consolidated-totals-summary__header">'
            .'<span class="owwa-consolidated-totals-summary__title">Consolidated for RIS</span>'
            .'<span class="owwa-consolidated-totals-summary__hint">Read-only totals — edit endorsed quantities above</span>'
            .'</div>'
            .'<div class="owwa-consolidated-totals-summary__table-wrap">'
            .'<table>'
            .'<thead><tr>'
            .'<th class="text-left">Category</th>'
            .'<th class="text-left">Item</th>'
            .'<th class="text-right">Available</th>'
            .'<th class="text-right">Requested</th>'
            .'<th class="text-right">Endorsed</th>'
            .'<th class="text-left">Sources</th>'
            .'</tr></thead>'
            .'<tbody>'.$body.'</tbody>'
            .'</table>'
            .'</div>'
            .'</div>'
        );
    }

    private static function endorsementLinesRepeater(): Repeater
    {
        return Repeater::make('endorsement_lines')
            ->hiddenLabel()
            ->extraAttributes(['class' => 'owwa-uc-endorsement-repeater'])
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->live()
            ->afterStateUpdated(function (?array $state, Set $set): void {
                if (blank($state)) {
                    $set('items', []);

                    return;
                }

                $compileService = app(RequisitionCompileService::class);
                $set('items', $compileService->mergedLineItemsAsRepeaterState(
                    $compileService->mergedLineItemsFromEndorsements($state),
                ));
            })
            ->table([
                TableColumn::make('Employee')->width('15%'),
                TableColumn::make('Purpose')->width('14%'),
                TableColumn::make('Item')->width('20%'),
                TableColumn::make('Available')->alignment(Alignment::End)->width('4.75rem'),
                TableColumn::make('Req.')->alignment(Alignment::End)->width('4.25rem'),
                TableColumn::make('Endorsed')->markAsRequired()->alignment(Alignment::End)->width('5.25rem'),
                TableColumn::make('Remarks')->width('18%'),
            ])
            ->compact()
            ->schema([
                Hidden::make('source_requisition_id'),
                Hidden::make('requisition_item_id'),
                Hidden::make('item_id'),
                Hidden::make('item_category_id'),
                Placeholder::make('employee_header')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => sprintf(
                        '%s — %s',
                        $get('transaction_number') ?? 'Requisition',
                        $get('employee_name') ?? 'Employee',
                    )),
                Placeholder::make('purpose_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => filled($get('purpose'))
                        ? (string) $get('purpose')
                        : '—'),
                Placeholder::make('item_name_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => (string) ($get('item_name') ?? '—')),
                Placeholder::make('regional_stock_available')
                    ->hiddenLabel()
                    ->content(function (Get $get): string {
                        $itemId = $get('item_id');
                        if (blank($itemId)) {
                            return '—';
                        }

                        $supplyOfficeId = app(SupplyOfficeResolver::class)->resolve();
                        if ($supplyOfficeId === null) {
                            return '—';
                        }

                        $stock = app(InventoryStockService::class)->getStock((int) $itemId, $supplyOfficeId);

                        return (string) max(0, $stock);
                    }),
                Placeholder::make('requested_quantity_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => (string) ((int) ($get('requested_quantity') ?? 0))),
                TextInput::make('endorsed_quantity')
                    ->label('Endorsed')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->hiddenLabel()
                    ->live(onBlur: true)
                    ->extraAttributes(['class' => 'owwa-uc-endorsed-qty'])
                    ->default(fn (Get $get): int => (int) ($get('requested_quantity') ?? 0)),
                Textarea::make('employee_remarks')
                    ->label('Remarks')
                    ->rows(1)
                    ->hiddenLabel()
                    ->placeholder('Required if reduced')
                    ->required(fn (Get $get): bool => (int) ($get('endorsed_quantity') ?? 0) < (int) ($get('requested_quantity') ?? 0)),
                Hidden::make('requested_quantity'),
                Hidden::make('transaction_number'),
                Hidden::make('employee_name'),
                Hidden::make('purpose'),
                Hidden::make('item_name'),
            ]);
    }

    private static function requestItemsRepeater(bool $isCustodian, bool $isUnitConsolidator, bool $isEmployee = false): Repeater
    {
        $repeater = Repeater::make('items')
            ->hiddenLabel()
            ->extraAttributes(['class' => 'owwa-requisition-items-repeater'])
            ->schema($isCustodian
                ? self::custodianRequestItemFields()
                : self::tableRequestItemFields($isUnitConsolidator))
            ->minItems(1)
            ->addActionLabel('Add another item');

        if (! $isUnitConsolidator) {
            $repeater->relationship('items');
        }

        if ($isCustodian) {
            return $repeater
                ->columns(2)
                ->grid([
                    'default' => 1,
                    'lg' => 2,
                ])
                ->itemLabel(function (array $state): ?string {
                    if (blank($state['item_id'] ?? null)) {
                        return null;
                    }

                    return Item::query()->find($state['item_id'])?->name;
                });
        }

        return $repeater
            ->table(fn (Get $get): array => self::requestItemsTableColumns($isUnitConsolidator, $get))
            ->compact()
            ->reorderable(false)
            ->deleteAction(self::repeaterDeleteVisibleExceptFirst())
            ->addable(fn (Get $get): bool => ! $isUnitConsolidator || blank($get('source_requisition_ids')))
            ->deletable(fn (Get $get): bool => ! $isUnitConsolidator || blank($get('source_requisition_ids')))
            ->defaultItems(fn (Get $get): int => filled($get('source_requisition_ids')) ? 0 : 1);
    }

    protected static function repeaterDeleteVisibleExceptFirst(): Closure
    {
        return fn (Action $action): Action => $action->visible(function (array $arguments, Repeater $component): bool {
            if (! $component->isDeletable()) {
                return false;
            }

            $itemKeys = array_keys($component->getRawState() ?? []);
            $index = array_search($arguments['item'] ?? null, $itemKeys, true);

            return $index !== false && $index > 0;
        });
    }

    /**
     * @return array<int, TableColumn>
     */
    private static function requestItemsTableColumns(bool $isUnitConsolidator, Get $get): array
    {
        return [
            TableColumn::make('Category')->markAsRequired()->alignment(Alignment::Start)->width('12rem; min-width: 11rem'),
            TableColumn::make('Item')->markAsRequired()->alignment(Alignment::Start)->width('auto'),
            TableColumn::make('Unit')->alignment(Alignment::Start)->width('5rem; min-width: 4.5rem'),
            TableColumn::make('Available')->alignment(Alignment::End)->width('5.5rem; min-width: 5rem'),
            TableColumn::make('Qty')->markAsRequired()->alignment(Alignment::End)->width('6.5rem; min-width: 6.5rem'),
            TableColumn::make('Restock')
                ->hiddenHeaderLabel()
                ->alignment(Alignment::Center)
                ->width('5.25rem; min-width: 4.75rem'),
        ];
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private static function tableRequestItemFields(bool $isUnitConsolidator): array
    {
        return [
            self::requestItemCategorySelect()
                ->disabled(fn (Get $get): bool => $isUnitConsolidator && filled($get('../../source_requisition_ids')))
                ->dehydrated(fn (Get $get): bool => ! $isUnitConsolidator || blank($get('../../source_requisition_ids'))),
            self::requestItemSelect()
                ->disabled(fn (Get $get): bool => $isUnitConsolidator && filled($get('../../source_requisition_ids')))
                ->dehydrated(),
            self::requestItemUnitPlaceholder(),
            self::requestItemRegionalStockPlaceholder(),
            self::requestItemQuantityInput()
                ->disabled(fn (Get $get): bool => $isUnitConsolidator && filled($get('../../source_requisition_ids')))
                ->dehydrated()
                ->extraInputAttributes(['class' => 'owwa-requisition-line-qty']),
            self::requestItemRestockStatusPlaceholder(),
            Hidden::make('requested_total'),
            Hidden::make('allocation_summary'),
            Hidden::make('stock_at_request'),
        ];
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private static function custodianRequestItemFields(): array
    {
        return [
            self::requestItemCategorySelect(),
            self::requestItemSelect(),
            TextInput::make('stock_available')
                ->label('Stock available')
                ->numeric()
                ->minValue(0)
                ->afterStateHydrated(function (TextInput $component, Get $get, $state): void {
                    if ($state !== null || blank($get('item_id'))) {
                        return;
                    }
                    $officeId = Filament::auth()->user()?->office_id;
                    if (! $officeId) {
                        return;
                    }
                    $stock = app(InventoryStockService::class)->getStock((int) $get('item_id'), (int) $officeId);
                    $component->state(max(0, $stock));
                }),
            self::requestItemQuantityInput(),
            TextInput::make('quantity_issued')
                ->label('Qty issued')
                ->numeric()
                ->minValue(0),
            self::requestItemRemarksInput(),
            TextInput::make('issue_remarks')
                ->label('Issue remarks')
                ->placeholder('Optional'),
        ];
    }

    private static function requestItemCategorySelect(): Select
    {
        return Select::make('item_category_id')
            ->label('Category')
            ->options(fn (): array => InventoryCategoryOptions::allActiveCategoryOptions())
            ->searchable()
            ->live()
            ->required()
            ->selectablePlaceholder(false)
            ->dehydrated(false)
            ->hiddenLabel()
            ->afterStateUpdated(function ($state, callable $set): void {
                $set('item_id', null);
            })
            ->afterStateHydrated(function (Select $component, $state, ?RequisitionItem $record): void {
                if (filled($state) || $record === null) {
                    return;
                }

                $record->loadMissing('item');
                $component->state($record->item?->item_category_id);
            });
    }

    private static function requestItemSelect(): Select
    {
        return Select::make('item_id')
            ->label('Item')
            ->options(function (Get $get): array {
                $categoryId = $get('item_category_id');

                if (blank($categoryId)) {
                    return [];
                }

                $items = Item::query()
                    ->active()
                    ->where('item_category_id', (int) $categoryId)
                    ->orderBy('name')
                    ->get(['id', 'name']);

                return $items
                    ->mapWithKeys(fn (Item $item): array => [$item->id => $item->name])
                    ->all();
            })
            ->searchable()
            ->preload()
            ->required()
            ->selectablePlaceholder(false)
            ->live()
            ->hiddenLabel()
            ->disabled(fn (Get $get): bool => blank($get('item_category_id')))
            ->placeholder(fn (Get $get): string => blank($get('item_category_id'))
                ? 'Choose a category first'
                : 'Select an item');
    }

    private static function requestItemUnitPlaceholder(): Placeholder
    {
        return Placeholder::make('unit_display')
            ->label('Unit')
            ->hiddenLabel()
            ->extraAttributes(['class' => 'owwa-requisition-line-unit'])
            ->content(function (Get $get): string {
                $itemId = $get('item_id');
                if (blank($itemId)) {
                    return '—';
                }

                $unit = Item::query()->whereKey($itemId)->value('unit');

                return filled($unit) ? (string) $unit : '—';
            });
    }

    private static function requestItemRegionalStockPlaceholder(): Placeholder
    {
        return Placeholder::make('regional_stock_available')
            ->label('Available')
            ->hiddenLabel()
            ->content(function (Get $get): string {
                $itemId = $get('item_id');
                if (blank($itemId)) {
                    return '—';
                }

                $supplyOfficeId = app(SupplyOfficeResolver::class)->resolve();
                if ($supplyOfficeId === null) {
                    return '—';
                }

                $stock = app(InventoryStockService::class)->getStock((int) $itemId, $supplyOfficeId);

                return (string) max(0, $stock);
            });
    }

    private static function requestItemRestockStatusPlaceholder(): Placeholder
    {
        return Placeholder::make('restock_status')
            ->label('Restock')
            ->hiddenLabel()
            ->content(function (Get $get): string|HtmlString {
                $itemId = $get('item_id');
                if (blank($itemId)) {
                    return '—';
                }

                $supplyOfficeId = app(SupplyOfficeResolver::class)->resolve();
                $statusService = app(RequisitionRestockStatusService::class);
                $status = $statusService->resolve((int) $itemId, $supplyOfficeId);
                $statusLabel = $statusService->displayLabel($status) ?? 'Active';
                $badgeClass = $status === RequisitionRestockStatusService::STATUS_ACTIVE || $status === null
                    ? 'owwa-badge owwa-badge-success'
                    : 'owwa-badge owwa-badge-warning';

                return new HtmlString(sprintf(
                    '<div class="owwa-requisition-restock-cell"><span class="%s">%s</span></div>',
                    e($badgeClass),
                    e($statusLabel),
                ));
            });
    }

    private static function requestItemQuantityInput(): TextInput
    {
        return TextInput::make('quantity')
            ->label('Qty')
            ->numeric()
            ->minValue(1)
            ->required()
            ->hiddenLabel();
    }

    private static function requestItemRemarksInput(bool $required = false): Textarea
    {
        return Textarea::make('remarks')
            ->label('Remarks')
            ->placeholder($required ? 'Add a brief note' : 'Optional')
            ->rows(2)
            ->required($required)
            ->hiddenLabel()
            ->extraAttributes(['class' => 'owwa-requisition-remarks-field']);
    }

    /**
     * @return array<int, string>
     */
    protected static function departmentOptionsForRequisition(
        Get $get,
        bool $isUnitConsolidator,
        ?User $user,
        callable $scopeActive,
    ): array {
        if ($user?->isEmployee()) {
            if (blank($user->department_id)) {
                return [];
            }

            $query = Department::query()->whereKey((int) $user->department_id);
            $scopeActive($query);

            return $query->orderBy('name')->pluck('name', 'id')->all();
        }

        $officeId = filled($get('office_id')) ? (int) $get('office_id') : null;

        $query = Department::query();
        $scopeActive($query);

        if ($officeId !== null) {
            $query->where('office_id', $officeId);
        }

        if ($isUnitConsolidator && $user instanceof User) {
            $assignedDepartmentIds = $user->assignments()
                ->when($officeId !== null, fn ($assignmentQuery) => $assignmentQuery->where('office_id', $officeId))
                ->pluck('department_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($assignedDepartmentIds !== []) {
                $query->whereIn('id', $assignedDepartmentIds);
            }
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function catalogPrefillState(int $itemId, ?int $categoryId = null): array
    {
        $item = Item::query()->find($itemId);

        if ($item === null) {
            return [];
        }

        $resolvedCategoryId = $categoryId > 0 ? $categoryId : $item->item_category_id;

        return [
            'items' => [
                [
                    'item_category_id' => $resolvedCategoryId,
                    'item_id' => $item->id,
                    'quantity' => 1,
                ],
            ],
        ];
    }
}
