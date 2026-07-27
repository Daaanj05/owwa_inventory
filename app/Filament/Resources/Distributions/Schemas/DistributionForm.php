<?php

namespace App\Filament\Resources\Distributions\Schemas;

use App\Models\Department;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\DistributionCompileService;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
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
use Filament\Support\Enums\Alignment;

class DistributionForm
{
    public static function configure(Schema $schema): Schema
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Distribution details')
                    ->description('Choose an assigned office and department, then select accepted employee requests.')
                    ->compact()
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('office_id')
                            ->label('Office')
                            ->options(fn (): array => Office::query()
                                ->active()
                                ->whereIn('id', $user?->assignedOfficeIds() ?? [])
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->required()
                            ->selectablePlaceholder(false)
                            ->default(fn (): ?int => $user?->hasSingleOfficeAssignment()
                                ? ($user->assignedOfficeIds()[0] ?? null)
                                : $user?->office_id)
                            ->afterStateUpdated(function (mixed $state, mixed $old, Get $get, Set $set) use ($user): void {
                                if ((int) ($state ?? 0) === (int) ($old ?? 0)) {
                                    return;
                                }

                                $set('source_requisition_ids', []);
                                $set('distribution_lines', []);

                                if (! $user instanceof User || blank($state)) {
                                    $set('department_id', null);

                                    return;
                                }

                                $departmentIds = $user->assignedDepartmentIdsForOffice((int) $state);
                                $currentDepartmentId = (int) ($get('department_id') ?? 0);

                                if (count($departmentIds) === 1) {
                                    $set('department_id', $departmentIds[0]);
                                } elseif (! in_array($currentDepartmentId, $departmentIds, true)) {
                                    $set('department_id', null);
                                }
                            }),
                        Select::make('department_id')
                            ->label('Department')
                            ->options(function (Get $get) use ($user): array {
                                $officeId = (int) ($get('office_id') ?? 0);

                                if (! $user instanceof User || $officeId <= 0) {
                                    return [];
                                }

                                return Department::query()
                                    ->active()
                                    ->where('office_id', $officeId)
                                    ->whereIn('id', $user->assignedDepartmentIdsForOffice($officeId))
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->searchable()
                            ->preload()
                            ->live()
                            ->required()
                            ->selectablePlaceholder(false)
                            ->default(function (Get $get) use ($user): ?int {
                                $officeId = (int) ($get('office_id') ?? 0);

                                if (! $user instanceof User || $officeId <= 0) {
                                    return $user?->department_id;
                                }

                                $departmentIds = $user->assignedDepartmentIdsForOffice($officeId);

                                return count($departmentIds) === 1 ? $departmentIds[0] : $user->department_id;
                            })
                            ->disabled(fn (Get $get): bool => blank($get('office_id')))
                            ->afterStateUpdated(function (mixed $state, mixed $old, Set $set): void {
                                if ((int) ($state ?? 0) === (int) ($old ?? 0)) {
                                    return;
                                }

                                $set('source_requisition_ids', []);
                                $set('distribution_lines', []);
                            }),
                        DatePicker::make('distribution_date')
                            ->label('Date')
                            ->required()
                            ->default(now())
                            ->columnSpanFull(),
                    ]),
                Section::make('Employee requisitions')
                    ->description('Only accepted employee requests with quantities still to distribute are listed.')
                    ->visible(fn (Get $get): bool => filled($get('office_id')) && filled($get('department_id')))
                    ->columnSpanFull()
                    ->schema([
                        CheckboxList::make('source_requisition_ids')
                            ->label('Employee requisitions to include')
                            ->options(function (Get $get) use ($user): array {
                                if (! $user instanceof User) {
                                    return [];
                                }

                                return app(DistributionCompileService::class)->eligibleEmployeeRequisitionOptions(
                                    $user,
                                    filled($get('office_id')) ? (int) $get('office_id') : null,
                                    filled($get('department_id')) ? (int) $get('department_id') : null,
                                );
                            })
                            ->required()
                            ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                            ->live()
                            ->afterStateUpdated(function (?array $state, Get $get, Set $set): void {
                                if (blank($state)) {
                                    $set('distribution_lines', []);

                                    return;
                                }

                                $requisitions = Requisition::query()
                                    ->whereIn('id', $state)
                                    ->with(['items.item', 'requestedBy'])
                                    ->get();

                                $set('distribution_lines', app(DistributionCompileService::class)->buildDistributionLines(
                                    $requisitions,
                                    (int) $get('office_id'),
                                ));
                            })
                            ->columnSpanFull()
                            ->helperText('Available is your office balance from SC issuance after prior distributions.'),
                    ]),
                Section::make('Distribution lines')
                    ->description('Adjust each quantity for a partial distribution when needed.')
                    ->visible(fn (Get $get): bool => filled($get('source_requisition_ids')))
                    ->columnSpanFull()
                    ->schema([
                        self::distributionLinesRepeater(),
                    ]),
            ]);
    }

    private static function distributionLinesRepeater(): Repeater
    {
        return Repeater::make('distribution_lines')
            ->hiddenLabel()
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->minItems(1)
            ->table([
                TableColumn::make('Employee')->width('16%'),
                TableColumn::make('Purpose')->width('16%'),
                TableColumn::make('Item')->width('18%'),
                TableColumn::make('Available')->alignment(Alignment::End)->width('5rem'),
                TableColumn::make('Req. qty')->alignment(Alignment::End)->width('5rem'),
                TableColumn::make('Qty to distribute')->markAsRequired()->alignment(Alignment::End)->width('7rem'),
                TableColumn::make('Remarks')->width('18%'),
            ])
            ->compact()
            ->schema([
                Placeholder::make('employee_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => sprintf(
                        '%s — %s',
                        $get('transaction_number') ?? 'Requisition',
                        $get('employee_name') ?? 'Employee',
                    )),
                Placeholder::make('purpose_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => filled($get('purpose')) ? (string) $get('purpose') : '—'),
                Placeholder::make('item_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => (string) ($get('item_name') ?? '—')),
                Placeholder::make('available_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => (string) ((int) ($get('available_quantity') ?? 0))),
                Placeholder::make('requested_display')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => (string) ((int) ($get('requested_quantity') ?? 0))),
                TextInput::make('quantity')
                    ->label('Qty to distribute')
                    ->hiddenLabel()
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(fn (Get $get): int => min(
                        (int) ($get('available_quantity') ?? 0),
                        (int) ($get('remaining_quantity') ?? 0),
                    ))
                    ->required(),
                Textarea::make('remarks')
                    ->hiddenLabel()
                    ->rows(1),
                Hidden::make('source_requisition_id'),
                Hidden::make('requisition_item_id'),
                Hidden::make('employee_id'),
                Hidden::make('employee_name'),
                Hidden::make('transaction_number'),
                Hidden::make('purpose'),
                Hidden::make('item_id'),
                Hidden::make('item_name'),
                Hidden::make('available_quantity'),
                Hidden::make('requested_quantity'),
                Hidden::make('remaining_quantity'),
            ]);
    }
}
