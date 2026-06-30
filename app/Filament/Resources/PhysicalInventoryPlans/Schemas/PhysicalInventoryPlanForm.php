<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Schemas;

use App\Models\ItemCategory;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class PhysicalInventoryPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeActive = fn ($query) => $query->active();
        $sessionCategoryId = filled(session('active_item_category_id'))
            ? (int) session('active_item_category_id')
            : null;
        $today = now()->startOfDay();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Schedule details')
                    ->description('Header info for this Inventory Schedule. Schedule lines below list each office and count date.')
                    ->columnSpanFull()
                    ->columns(3)
                    ->compact()
                    ->schema([
                        TextInput::make('title')
                            ->label('Title')
                            ->placeholder('e.g. FY 2026 Year-end Semi-Expendable Count')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Short name shown in the list and reminders.')
                            ->hintIcon(Heroicon::QuestionMarkCircle, 'Identifies this schedule in notifications and exports.'),
                        TextInput::make('period_label')
                            ->label('Period label (optional)')
                            ->placeholder('FY 2026')
                            ->maxLength(100)
                            ->helperText('Optional label for reports; not linked to the Fiscal Years table.')
                            ->hintIcon(Heroicon::QuestionMarkCircle, 'Display-only text such as fiscal year or quarter. Leave blank if not needed.'),
                        DatePicker::make('cut_off_date')
                            ->label('Cut-off date')
                            ->required()
                            ->minDate($today)
                            ->helperText('Last date a count may be scheduled; inventory is as-of this date.')
                            ->hintIcon(Heroicon::QuestionMarkCircle, 'Must be today or later. Every planned date must fall on or before cut-off.'),
                        DatePicker::make('coa_submitted_at')
                            ->label('COA submission date (optional)')
                            ->helperText('Must be at least 10 days before the first scheduled count.')
                            ->hintIcon(Heroicon::QuestionMarkCircle, 'Date COA paperwork was or will be submitted to support the year-end report.'),
                        TextInput::make('committee_chair_name')
                            ->label('Committee chair (optional)')
                            ->placeholder('Full name')
                            ->maxLength(255)
                            ->helperText('Printed on committee signatory blocks when exported.')
                            ->hintIcon(Heroicon::QuestionMarkCircle, 'Chair of the physical inventory committee.'),
                        TextInput::make('property_officer_name')
                            ->label('Property officer (optional)')
                            ->placeholder('Full name')
                            ->maxLength(255)
                            ->helperText('Printed on committee signatory blocks when exported.')
                            ->hintIcon(Heroicon::QuestionMarkCircle, 'Property officer signatory for the count.'),
                        TextInput::make('accounting_officer_name')
                            ->label('Accounting officer (optional)')
                            ->placeholder('Full name')
                            ->maxLength(255)
                            ->helperText('Printed on committee signatory blocks when exported.')
                            ->hintIcon(Heroicon::QuestionMarkCircle, 'Accounting officer signatory for the count.'),
                        ...self::itemCategoryFields($sessionCategoryId),
                    ]),
                Section::make('Schedule')
                    ->description('Add one row per office and count date.')
                    ->columnSpanFull()
                    ->compact()
                    ->schema([
                        Repeater::make('lines')
                            ->relationship()
                            ->label('Schedule lines')
                            ->compact()
                            ->helperText('Add one row per office and count date.')
                            ->hintIcon(Heroicon::QuestionMarkCircle, 'Each line becomes a separate physical count. Duplicate office + category in the same schedule is not allowed.')
                            ->schema([
                                Select::make('office_id')
                                    ->label('Office')
                                    ->relationship('office', 'name', $scopeActive)
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->placeholder('Select office')
                                    ->helperText('Active office that will perform the count.')
                                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Regional or satellite office included in this schedule.'),
                                Select::make('item_category_id')
                                    ->label('Category')
                                    ->options(fn (): array => ItemCategory::query()
                                        ->whereNull('archived_at')
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->default($sessionCategoryId)
                                    ->required()
                                    ->searchable()
                                    ->helperText('Consumables, Semi-Expendable, PPE, etc.')
                                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Determines the count form (RPCI, RPCPPE, or RPCSP) when you start the count.'),
                                DatePicker::make('planned_date')
                                    ->label('Planned date')
                                    ->required()
                                    ->minDate($today)
                                    ->helperText('Target date for this office\'s physical count.')
                                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Must be today or later and on or before the cut-off date.'),
                            ])
                            ->columns(3)
                            ->defaultItems(1)
                            ->addActionLabel('Add schedule line')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, Hidden|Select>
     */
    protected static function itemCategoryFields(?int $sessionCategoryId): array
    {
        if ($sessionCategoryId !== null && $sessionCategoryId > 0) {
            return [
                Hidden::make('item_category_id')
                    ->default($sessionCategoryId),
            ];
        }

        return [
            Select::make('item_category_id')
                ->label('Default category (optional)')
                ->options(fn (): array => ItemCategory::query()
                    ->whereNull('archived_at')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->columnSpanFull()
                ->helperText('Pre-fills category on new schedule lines.')
                ->hintIcon(Heroicon::QuestionMarkCircle, 'When set, new lines inherit this category. You can still change category per line.'),
        ];
    }
}
