<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Schemas;

use App\Filament\Concerns\SyncsActiveItemCategory;
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
        $sessionCategoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();
        $today = now()->startOfDay();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Schedule details')
                    ->description('Title and cut-off first. Add count dates below. Every active office is included automatically.')
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
                        DatePicker::make('cut_off_date')
                            ->label('Cut-off date')
                            ->required()
                            ->minDate($today)
                            ->helperText('Last date a count may be scheduled; inventory is as-of this date.')
                            ->hintIcon(Heroicon::QuestionMarkCircle, 'Must be today or later. Every planned date must fall on or before cut-off.'),
                        TextInput::make('period_label')
                            ->label('Period label (optional)')
                            ->placeholder('FY 2026')
                            ->maxLength(100)
                            ->helperText('Optional label for reports.')
                            ->hintIcon(Heroicon::QuestionMarkCircle, 'Display-only text such as fiscal year or quarter. Leave blank if not needed.'),
                        DatePicker::make('coa_submitted_at')
                            ->label('COA submission date (optional)')
                            ->helperText('Must be at least 10 days before the first scheduled count.')
                            ->hintIcon(Heroicon::QuestionMarkCircle, 'Date COA paperwork was or will be submitted to support the year-end report.'),
                        ...self::itemCategoryFields($sessionCategoryId),
                    ]),
                Section::make('Schedule')
                    ->description('Add one row per category and count date. All active offices are scheduled automatically.')
                    ->columnSpanFull()
                    ->compact()
                    ->schema([
                        Repeater::make('schedule_slots')
                            ->label('Schedule dates')
                            ->compact()
                            ->helperText('Each row covers every active office for that category and date.')
                            ->hintIcon(Heroicon::QuestionMarkCircle, 'Supply custodian inventory includes all offices. Duplicate category in the same schedule is not allowed.')
                            ->schema([
                                Select::make('item_category_id')
                                    ->label('Category')
                                    ->options(fn (): array => ItemCategory::query()
                                        ->whereNull('archived_at')
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->default($sessionCategoryId)
                                    ->required()
                                    ->placeholder('Select category')
                                    ->selectablePlaceholder(false)
                                    ->helperText('Consumables, Semi-Expendable, PPE, etc.')
                                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Determines the count form (RPCI, RPCPPE, or RPCSP) when you start the count.'),
                                DatePicker::make('planned_date')
                                    ->label('Planned date')
                                    ->required()
                                    ->minDate($today)
                                    ->helperText('Target date for the physical count across all offices.')
                                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Must be today or later and on or before the cut-off date.'),
                            ])
                            ->columns(2)
                            ->defaultItems(1)
                            ->addActionLabel('Add schedule date')
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
                ->placeholder('Select category')
                ->selectablePlaceholder(false)
                ->columnSpanFull()
                ->helperText('Pre-fills category on new schedule dates.')
                ->hintIcon(Heroicon::QuestionMarkCircle, 'When set, new rows inherit this category. You can still change category per row.'),
        ];
    }
}
