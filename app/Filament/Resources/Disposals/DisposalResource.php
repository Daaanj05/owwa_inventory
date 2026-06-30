<?php

namespace App\Filament\Resources\Disposals;

use App\Filament\Concerns\HasOwwaViewModalUrl;
use App\Filament\Resources\Disposals\Pages\ListDisposals;
use App\Filament\Resources\Disposals\Pages\ViewDisposal;
use App\Filament\Resources\Disposals\Schemas\DisposalForm;
use App\Filament\Resources\Disposals\Tables\DisposalsTable;
use App\Models\Disposal;
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

class DisposalResource extends Resource
{
    use HasOwwaViewModalUrl;

    protected static ?string $model = Disposal::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $categoryId = session('active_item_category_id');
        if (filled($categoryId)) {
            $query->whereHas('item', function (Builder $itemQuery) use ($categoryId): void {
                $itemQuery->where('item_category_id', (int) $categoryId);
            });
        } else {
            // Don't show disposals until the user selects a category.
            $query->whereRaw('1 = 0');
        }

        $query->where('disposal_type', '!=', 'lost_stolen_damaged');

        return CustodianOfficeScope::applyOfficeColumn($query);
    }

    public static function form(Schema $schema): Schema
    {
        return DisposalForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Disposal Details')
                    ->schema([
                        TextEntry::make('reference_code')
                            ->label(fn (Disposal $record): string => OwwaReferenceLabels::disposal(
                                $record->item?->category?->getTemplateSlug()
                            )),
                        TextEntry::make('disposal_type')->label('Type')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'waste_sale' => 'Waste / Sale',
                                'unserviceable' => 'Unserviceable',
                                'lost_stolen_damaged' => 'Lost / Damaged',
                                default => $state ?? '—',
                            })
                            ->color(fn (?string $state): string => match ($state) {
                                'waste_sale' => 'warning',
                                'unserviceable' => 'gray',
                                'lost_stolen_damaged' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('office.name')->label('Office'),
                        TextEntry::make('item.name')->label('Item'),
                        TextEntry::make('quantity')->label('Quantity'),
                        TextEntry::make('disposal_date')->label('Date')->date('M d, Y'),
                        TextEntry::make('asset_identifier')
                            ->label(fn (Disposal $record): string => OwwaReferenceLabels::assetIdentifierLabel(
                                $record->item?->category?->getTemplateSlug()
                            ))
                            ->state(fn (Disposal $record): ?string => OwwaReferenceLabels::assetIdentifierForDisposal($record))
                            ->placeholder('—'),
                        TextEntry::make('acquisition_cost')->label('Acquisition cost')->money('PHP')->placeholder('—'),
                        TextEntry::make('reason')->label('Reason')->placeholder('—'),
                        TextEntry::make('remarks')->label('Remarks')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Sale Details')
                    ->schema([
                        TextEntry::make('official_receipt_no')->label('Official receipt number')->placeholder('—'),
                        TextEntry::make('sale_date')->label('Date of sale')->date('M d, Y')->placeholder('—'),
                        TextEntry::make('sale_amount')->label('Sale amount')->money('PHP')->placeholder('—'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Signatures')
                    ->schema([
                        TextEntry::make('custodian_printed_name')->label('Custodian')->placeholder('—'),
                        TextEntry::make('approved_by_printed_name')->label('Approved by')->placeholder('—'),
                        TextEntry::make('inspection_officer_printed_name')->label('Inspection officer')->placeholder('—'),
                        TextEntry::make('witness_printed_name')->label('Witness')->placeholder('—'),
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
            Section::make('Disposal Details')
                ->schema([
                    TextEntry::make('asset_identifier')
                        ->label(fn (Disposal $record): string => OwwaReferenceLabels::assetIdentifierLabel(
                            $record->item?->category?->getTemplateSlug()
                        ))
                        ->state(fn (Disposal $record): ?string => OwwaReferenceLabels::assetIdentifierForDisposal($record))
                        ->placeholder('—'),
                    TextEntry::make('acquisition_cost')->label('Acquisition cost')->money('PHP')->placeholder('—'),
                    TextEntry::make('reason')->label('Reason')->placeholder('—'),
                    TextEntry::make('remarks')->label('Remarks')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Sale Details')
                ->schema([
                    TextEntry::make('official_receipt_no')->label('Official receipt number')->placeholder('—'),
                    TextEntry::make('sale_date')->label('Date of sale')->date('M d, Y')->placeholder('—'),
                    TextEntry::make('sale_amount')->label('Sale amount')->money('PHP')->placeholder('—'),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Signatures')
                ->schema([
                    TextEntry::make('custodian_printed_name')->label('Custodian')->placeholder('—'),
                    TextEntry::make('approved_by_printed_name')->label('Approved by')->placeholder('—'),
                    TextEntry::make('inspection_officer_printed_name')->label('Inspection officer')->placeholder('—'),
                    TextEntry::make('witness_printed_name')->label('Witness')->placeholder('—'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return DisposalsTable::configure($table);
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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisposals::route('/'),
            'view' => ViewDisposal::route('/{record}'),
        ];
    }
}
