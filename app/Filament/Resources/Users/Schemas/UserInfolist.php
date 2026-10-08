<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Alignment;

class UserInfolist
{
    /**
     * @return array<int, TextEntry|Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            TextEntry::make('email_verified_at')
                ->label('Verification')
                ->badge()
                ->state(fn (User $record): string => $record->hasVerifiedEmail() ? 'Verified' : 'Pending')
                ->color(fn (User $record): string => $record->hasVerifiedEmail() ? 'success' : 'warning'),
            TextEntry::make('pendingPasswordResetRequest.requested_at')
                ->label('Password reset')
                ->badge()
                ->state(fn (User $record): string => 'Reset requested')
                ->color('warning')
                ->visible(fn (User $record): bool => $record->pendingPasswordResetRequest !== null),
            TextEntry::make('department.name')
                ->label('Sub-Office/Department')
                ->placeholder('—')
                ->visible(fn (User $record): bool => ! $record->isUnitConsolidator()),
            Section::make('Handled offices & sub-offices/departments')
                ->visible(fn (User $record): bool => $record->isUnitConsolidator())
                ->schema([
                    RepeatableEntry::make('assignments')
                        ->hiddenLabel()
                        ->contained(false)
                        ->table([
                            TableColumn::make('Office')
                                ->alignment(Alignment::Start),
                            TableColumn::make('Sub-Office/Department')
                                ->alignment(Alignment::Start),
                        ])
                        ->schema([
                            TextEntry::make('office.name')
                                ->hiddenLabel()
                                ->placeholder('—'),
                            TextEntry::make('department.name')
                                ->hiddenLabel()
                                ->placeholder('—'),
                        ])
                        ->getStateUsing(function (User $record): array {
                            $record->loadMissing(['assignments.office', 'assignments.department']);

                            return $record->assignments
                                ->sortBy('id')
                                ->values()
                                ->all();
                        }),
                ]),
        ];
    }
}
