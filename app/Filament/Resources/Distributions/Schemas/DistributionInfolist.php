<?php

namespace App\Filament\Resources\Distributions\Schemas;

use App\Support\OwwaReferenceLabels;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DistributionInfolist
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
        return Section::make('Distribution details')
            ->columns(2)
            ->schema([
                TextEntry::make('office.name')
                    ->label('Office')
                    ->placeholder('—'),
                TextEntry::make('department.name')
                    ->label('Department')
                    ->placeholder('—'),
                TextEntry::make('distributedBy.name')
                    ->label('Distributed by')
                    ->placeholder('—'),
                TextEntry::make('requisition.reference_code')
                    ->label(OwwaReferenceLabels::requisition())
                    ->placeholder('—'),
                TextEntry::make('remarks')
                    ->label('Remarks')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }
}
