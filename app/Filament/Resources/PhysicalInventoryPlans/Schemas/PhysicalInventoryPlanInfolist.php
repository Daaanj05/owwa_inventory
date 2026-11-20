<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Schemas;

use App\Models\PhysicalInventoryPlan;
use App\Support\OwwaReferenceLabels;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PhysicalInventoryPlanInfolist
{
    /**
     * @return array<int, \Filament\Schemas\Components\Component|\Filament\Infolists\Components\Component>
     */
    public static function modalDetailSections(): array
    {
        return [
            self::modalDetailsSection(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::overviewSection(),
            ]);
    }

    public static function modalDetailsSection(): Section
    {
        return Section::make('Details')
            ->columnSpanFull()
            ->columns(3)
            ->compact()
            ->schema([
                TextEntry::make('period_label')->label('Period')->placeholder('—'),
                TextEntry::make('cut_off_date')->label('Cut-off')->date('M j, Y'),
                TextEntry::make('coa_submitted_at')
                    ->label('COA submitted')
                    ->date('M j, Y')
                    ->placeholder('—'),
                TextEntry::make('recordedBy.name')
                    ->label('Prepared by')
                    ->placeholder('—'),
                TextEntry::make('progress_summary')
                    ->label('Progress')
                    ->state(fn (PhysicalInventoryPlan $record): string => (function () use ($record): string {
                        $counts = $record->progressCounts();
                        $percent = $record->progressPercent();

                        return "{$counts['completed']} of {$counts['total']} complete ({$percent}%)";
                    })())
                    ->columnSpan(2),
            ]);
    }

    public static function overviewSection(): Section
    {
        return Section::make('Schedule overview')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                TextEntry::make('reference_code')->label(OwwaReferenceLabels::inventoryPlan()),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PhysicalInventoryPlan $record): string => $record->statusLabel())
                    ->color(fn (PhysicalInventoryPlan $record): string => $record->statusColor()),
                TextEntry::make('title')->columnSpanFull(),
                TextEntry::make('period_label')->label('Period')->placeholder('—'),
                TextEntry::make('cut_off_date')->label('Cut-off')->date('M j, Y'),
                TextEntry::make('recordedBy.name')
                    ->label('Prepared by')
                    ->placeholder('—'),
                TextEntry::make('progress_summary')
                    ->label('Progress')
                    ->state(fn (PhysicalInventoryPlan $record): string => (function () use ($record): string {
                        $counts = $record->progressCounts();
                        $percent = $record->progressPercent();

                        return "{$counts['completed']} of {$counts['total']} complete ({$percent}%)";
                    })()),
                TextEntry::make('coa_submitted_at')
                    ->label('COA submitted')
                    ->date('M j, Y')
                    ->placeholder('—'),
            ]);
    }
}
