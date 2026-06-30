<?php

namespace App\Filament\Resources\IncidentReports;

use App\Filament\Concerns\HasOwwaViewModalUrl;
use App\Filament\Resources\IncidentReports\Pages\ListIncidentReports;
use App\Filament\Resources\IncidentReports\Pages\ViewIncidentReport;
use App\Filament\Resources\IncidentReports\Schemas\IncidentReportForm;
use App\Filament\Resources\IncidentReports\Tables\IncidentReportsTable;
use App\Models\Disposal;
use App\Models\ItemCategory;
use App\Support\CustodianOfficeScope;
use App\Support\OwwaReferenceLabels;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class IncidentReportResource extends Resource
{
    use HasOwwaViewModalUrl;

    protected static ?string $model = Disposal::class;

    protected static ?string $slug = 'incident-reports';

    protected static ?string $navigationLabel = 'Incident reports';

    protected static ?string $modelLabel = 'Incident report';

    protected static ?string $pluralModelLabel = 'Incident reports';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 50;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->where('disposal_type', 'lost_stolen_damaged')
            ->whereHas('item', function (Builder $itemQuery): void {
                $itemQuery->whereIn('item_category_id', self::incidentCategoryIds());
            });

        return CustodianOfficeScope::applyOfficeColumn($query);
    }

    /**
     * @return array<int, int>
     */
    public static function incidentCategoryIds(): array
    {
        return ItemCategory::query()
            ->get()
            ->filter(fn (ItemCategory $category): bool => in_array(
                $category->getTemplateSlug(),
                ['ppe', 'semi_expendable'],
                true,
            ))
            ->pluck('id')
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return IncidentReportForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Incident report')
                    ->schema([
                        TextEntry::make('reference_code')
                            ->label(fn (): string => OwwaReferenceLabels::incidentReport()),
                        TextEntry::make('property_status')
                            ->label('Property status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : '—'),
                        TextEntry::make('office.name')->label('Office'),
                        TextEntry::make('department.name')->label('Department')->placeholder('—'),
                        TextEntry::make('item.name')->label('Item'),
                        TextEntry::make('item.category.name')->label('Category'),
                        TextEntry::make('quantity')->label('Quantity'),
                        TextEntry::make('disposal_date')->label('Date')->date('M d, Y'),
                        TextEntry::make('asset_identifier')
                            ->label(fn (Disposal $record): string => OwwaReferenceLabels::assetIdentifierLabel(
                                $record->item?->category?->getTemplateSlug()
                            ))
                            ->state(fn (Disposal $record): ?string => OwwaReferenceLabels::assetIdentifierForDisposal($record))
                            ->placeholder('—'),
                        TextEntry::make('acquisition_cost')->label('Acquisition cost')->money('PHP')->placeholder('—'),
                        TextEntry::make('circumstances')->label('Circumstances')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            Section::make('Incident details')
                ->schema([
                    TextEntry::make('asset_identifier')
                        ->label(fn (Disposal $record): string => OwwaReferenceLabels::assetIdentifierLabel(
                            $record->item?->category?->getTemplateSlug()
                        ))
                        ->state(fn (Disposal $record): ?string => OwwaReferenceLabels::assetIdentifierForDisposal($record))
                        ->placeholder('—'),
                    TextEntry::make('acquisition_cost')->label('Acquisition cost')->money('PHP')->placeholder('—'),
                    TextEntry::make('circumstances')->label('Circumstances')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return IncidentReportsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIncidentReports::route('/'),
            'view' => ViewIncidentReport::route('/{record}'),
        ];
    }
}
