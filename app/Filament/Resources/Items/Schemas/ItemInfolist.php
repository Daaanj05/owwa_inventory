<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Models\Item;
use App\Support\ConsumableInventoryType;
use App\Support\ItemPropertyClass;
use App\Support\PpePropertyType;
use App\Support\SemiExpendableValueCategory;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::sections());
    }

    /**
     * @return array<int, Section>
     */
    public static function sections(): array
    {
        return [
            self::detailsSection(),
        ];
    }

    /**
     * @return array<int, Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            self::detailsSection(),
        ];
    }

    public static function detailsSection(): Section
    {
        return Section::make('Item details')
            ->columns(2)
            ->schema([
                TextEntry::make('base_name')
                    ->label('Base item')
                    ->placeholder('—'),
                TextEntry::make('sub_item')
                    ->label('Sub-item')
                    ->placeholder('—'),
                TextEntry::make('days_to_consume')
                    ->label('Days to consume')
                    ->placeholder('—')
                    ->visible(fn (Item $record): bool => $record->category?->getTemplateSlug() === 'consumables'),
                TextEntry::make('inventory_type')
                    ->label('Inventory type')
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? (ConsumableInventoryType::label($state) ?: $state)
                        : '—')
                    ->placeholder('—')
                    ->visible(fn (Item $record): bool => $record->category?->getTemplateSlug() === 'consumables'),
                TextEntry::make('description')
                    ->label('Description')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('estimated_useful_life')
                    ->label('Estimated useful life')
                    ->placeholder('—')
                    ->visible(fn (Item $record): bool => in_array(
                        $record->category?->getTemplateSlug(),
                        ['semi_expendable', 'ppe'],
                        true,
                    )),
                TextEntry::make('property_class')
                    ->label('Property class')
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? (ItemPropertyClass::options()[$state] ?? $state)
                        : '—')
                    ->placeholder('—')
                    ->visible(fn (Item $record): bool => $record->category?->getTemplateSlug() === 'semi_expendable'),
                TextEntry::make('ppe_type')
                    ->label('Type of PPE')
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? (PpePropertyType::options()[$state] ?? $state)
                        : '—')
                    ->placeholder('—')
                    ->visible(fn (Item $record): bool => $record->category?->getTemplateSlug() === 'ppe'),
                TextEntry::make('value_type')
                    ->label('Value category')
                    ->formatStateUsing(fn (?string $state): string => SemiExpendableValueCategory::labelForValueType($state))
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'high' ? 'warning' : 'gray')
                    ->visible(fn (Item $record): bool => $record->category?->getTemplateSlug() === 'semi_expendable'),
            ]);
    }
}
