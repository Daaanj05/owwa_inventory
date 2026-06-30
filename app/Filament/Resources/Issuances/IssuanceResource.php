<?php

namespace App\Filament\Resources\Issuances;

use App\Filament\Concerns\HasOwwaViewModalUrl;
use App\Filament\Resources\Issuances\Pages\ListIssuances;
use App\Filament\Resources\Issuances\Pages\ViewIssuance;
use App\Filament\Resources\Issuances\Schemas\IssuanceForm;
use App\Filament\Resources\Issuances\Tables\IssuancesTable;
use App\Models\Issuance;
use App\Models\User;
use App\Support\OwwaReferenceLabels;
use App\Support\SemiExpendableUsefulLife;
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

class IssuanceResource extends Resource
{
    use HasOwwaViewModalUrl;

    protected static ?string $model = Issuance::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Issuance';

    protected static ?string $pluralModelLabel = 'Issuances';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();
        if ($user && $user->isUnitConsolidator() && $user->office_id) {
            $query->where('office_id', $user->office_id);
        }

        $categoryId = session('active_item_category_id');
        if (filled($categoryId)) {
            $query->whereHas('item', function (Builder $itemQuery) use ($categoryId): void {
                $itemQuery->where('item_category_id', (int) $categoryId);
            });
        } else {
            // Don't show issuances until the user selects a category.
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return IssuanceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaction Details')
                    ->schema([
                        TextEntry::make('reference_code')
                            ->label(fn (Issuance $record): string => OwwaReferenceLabels::forIssuance($record)),
                        TextEntry::make('requisition.reference_code')
                            ->label(OwwaReferenceLabels::RIS)
                            ->placeholder('—')
                            ->helperText(fn (Issuance $record): ?string => $record->requisition_id
                                ? null
                                : 'Legacy record without a linked requisition.'),
                        TextEntry::make('issuance_date')->label('Date')->date('M d, Y'),
                        TextEntry::make('office.name')->label('Office'),
                        TextEntry::make('department.name')->label('Department')->placeholder('—'),
                        TextEntry::make('item.name')->label('Item'),
                        TextEntry::make('quantity')->label('Quantity'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Pricing')
                    ->schema([
                        TextEntry::make('unit_cost')->label('Unit cost (₱ per measurement unit)')->money('PHP'),
                        TextEntry::make('amount')->label('Amount')->money('PHP'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Assignment')
                    ->schema([
                        TextEntry::make('issuedTo.name')->label('Issued to')->placeholder('—'),
                        TextEntry::make('asset_identifier')
                            ->label(fn (Issuance $record): string => OwwaReferenceLabels::assetIdentifierLabel(
                                $record->item?->category?->getTemplateSlug()
                            ))
                            ->state(fn (Issuance $record): ?string => OwwaReferenceLabels::assetIdentifierForIssuance($record))
                            ->placeholder('—'),
                        TextEntry::make('remarks')->label('Remarks')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Useful life')
                    ->schema([
                        TextEntry::make('estimated_useful_life')
                            ->label('Estimated useful life')
                            ->placeholder('—')
                            ->visible(fn (Issuance $record): bool => $record->item?->category?->getTemplateSlug() === 'semi_expendable'),
                        TextEntry::make('eul_expires_at')
                            ->label('Useful life expires')
                            ->date('M d, Y')
                            ->placeholder('—')
                            ->visible(fn (Issuance $record): bool => $record->item?->category?->getTemplateSlug() === 'semi_expendable'),
                        TextEntry::make('eul_status')
                            ->label('EUL status')
                            ->state(fn (Issuance $record): string => SemiExpendableUsefulLife::statusLabel(
                                SemiExpendableUsefulLife::statusForIssuance($record)
                            ))
                            ->visible(fn (Issuance $record): bool => $record->item?->category?->getTemplateSlug() === 'semi_expendable'),
                    ])
                    ->columns(3)
                    ->columnSpanFull()
                    ->visible(fn (Issuance $record): bool => $record->item?->category?->getTemplateSlug() === 'semi_expendable'),
            ]);
    }

    /**
     * @return array<int, Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            Section::make('Transaction Details')
                ->schema([
                    TextEntry::make('requisition.reference_code')
                        ->label(OwwaReferenceLabels::RIS)
                        ->placeholder('—')
                        ->helperText(fn (Issuance $record): ?string => $record->requisition_id
                            ? null
                            : 'Legacy record without a linked requisition.'),
                    TextEntry::make('office.name')->label('Office'),
                    TextEntry::make('department.name')->label('Department')->placeholder('—'),
                    TextEntry::make('unit_cost')->label('Unit cost (₱ per measurement unit)')->money('PHP'),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Assignment')
                ->schema([
                    TextEntry::make('issuedTo.name')->label('Issued to')->placeholder('—'),
                    TextEntry::make('asset_identifier')
                        ->label(fn (Issuance $record): string => OwwaReferenceLabels::assetIdentifierLabel(
                            $record->item?->category?->getTemplateSlug()
                        ))
                        ->state(fn (Issuance $record): ?string => OwwaReferenceLabels::assetIdentifierForIssuance($record))
                        ->placeholder('—'),
                    TextEntry::make('remarks')->label('Remarks')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Useful life')
                ->schema([
                    TextEntry::make('estimated_useful_life')
                        ->label('Estimated useful life')
                        ->placeholder('—'),
                    TextEntry::make('eul_expires_at')
                        ->label('Useful life expires')
                        ->date('M d, Y')
                        ->placeholder('—'),
                    TextEntry::make('eul_status')
                        ->label('EUL status')
                        ->state(fn (Issuance $record): string => SemiExpendableUsefulLife::statusLabel(
                            SemiExpendableUsefulLife::statusForIssuance($record)
                        )),
                ])
                ->columns(3)
                ->columnSpanFull()
                ->visible(fn (Issuance $record): bool => $record->item?->category?->getTemplateSlug() === 'semi_expendable'),
        ];
    }

    public static function table(Table $table): Table
    {
        return IssuancesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIssuances::route('/'),
            'view' => ViewIssuance::route('/{record}'),
        ];
    }
}
