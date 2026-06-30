<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Schemas;

use App\Models\PhysicalInventoryPlan;
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
            self::modalCommitteeSection(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::overviewSection(),
                self::committeeSection(),
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
                TextEntry::make('approved_at')
                    ->label('Approved')
                    ->dateTime('M j, Y g:i A')
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

    public static function modalCommitteeSection(): Section
    {
        return Section::make('Committee')
            ->columnSpanFull()
            ->columns(3)
            ->compact()
            ->collapsed(fn (PhysicalInventoryPlan $record): bool => blank($record->committee_chair_name)
                && blank($record->property_officer_name)
                && blank($record->accounting_officer_name))
            ->schema([
                TextEntry::make('committee_chair_name')->label('Chair')->placeholder('—'),
                TextEntry::make('property_officer_name')->label('Property officer')->placeholder('—'),
                TextEntry::make('accounting_officer_name')->label('Accounting officer')->placeholder('—'),
            ]);
    }

    public static function overviewSection(): Section
    {
        return Section::make('Schedule overview')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                TextEntry::make('reference_code')->label('Reference'),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        PhysicalInventoryPlan::STATUS_DRAFT => 'Draft',
                        PhysicalInventoryPlan::STATUS_APPROVED => 'Approved',
                        PhysicalInventoryPlan::STATUS_IN_PROGRESS => 'In progress',
                        PhysicalInventoryPlan::STATUS_COMPLETED => 'Completed',
                        PhysicalInventoryPlan::STATUS_CANCELLED => 'Cancelled',
                        default => ucfirst((string) $state),
                    }),
                TextEntry::make('title')->columnSpanFull(),
                TextEntry::make('period_label')->label('Period')->placeholder('—'),
                TextEntry::make('cut_off_date')->label('Cut-off')->date('M j, Y'),
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
                TextEntry::make('approved_at')
                    ->label('Approved')
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('—'),
            ]);
    }

    public static function committeeSection(): Section
    {
        return Section::make('Committee')
            ->columnSpanFull()
            ->columns(3)
            ->schema([
                TextEntry::make('committee_chair_name')->label('Chair')->placeholder('—'),
                TextEntry::make('property_officer_name')->label('Property officer')->placeholder('—'),
                TextEntry::make('accounting_officer_name')->label('Accounting officer')->placeholder('—'),
            ]);
    }
}
