<?php

namespace App\Filament\Resources\PropertyActionRequests\Schemas;

use App\Models\PropertyActionRequest;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

class PropertyActionRequestInfolistSchema
{
    /**
     * @return array<int, \Filament\Schemas\Components\Component|\Filament\Infolists\Components\Component>
     */
    public static function modalDetailSections(): array
    {
        return [
            Section::make('Property Return details')
                ->columns(2)
                ->schema(self::detailFields()),
            self::requestedItemsSection(),
        ];
    }

    /**
     * @return array<int, TextEntry>
     */
    protected static function detailFields(): array
    {
        return [
            TextEntry::make('reference_code')
                ->label('Reference')
                ->placeholder('—'),
            TextEntry::make('action_type')
                ->label('Action')
                ->formatStateUsing(fn (PropertyActionRequest $record): string => $record->actionTypeLabel())
                ->placeholder('—'),
            TextEntry::make('reason_code')
                ->label('Reason')
                ->formatStateUsing(fn (PropertyActionRequest $record): string => $record->reasonLabel())
                ->placeholder('—'),
            TextEntry::make('reason_detail')
                ->label('Details')
                ->placeholder('—')
                ->columnSpanFull(),
            TextEntry::make('requestedBy.name')
                ->label('Requested by')
                ->placeholder('—'),
            TextEntry::make('accountableUser.name')
                ->label('Accountable UC')
                ->placeholder('—'),
            TextEntry::make('office.name')
                ->label('Office')
                ->placeholder('—'),
            TextEntry::make('department.name')
                ->label('Department')
                ->placeholder('—'),
            TextEntry::make('created_at')
                ->label('Date filed')
                ->date('M d, Y')
                ->placeholder('—'),
            TextEntry::make('status')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => str_replace('_', ' ', ucwords((string) $state, '_')))
                ->color(fn (?string $state): string => match ($state) {
                    PropertyActionRequest::STATUS_APPROVED, PropertyActionRequest::STATUS_EXECUTED => 'success',
                    PropertyActionRequest::STATUS_REJECTED => 'danger',
                    PropertyActionRequest::STATUS_PENDING_UC, PropertyActionRequest::STATUS_PENDING_SC => 'warning',
                    default => 'gray',
                }),
            TextEntry::make('uc_approvedBy.name')
                ->label('UC actioned by')
                ->placeholder('—'),
            TextEntry::make('uc_approved_at')
                ->label('UC actioned on')
                ->dateTime('M d, Y h:i A')
                ->placeholder('—'),
            TextEntry::make('uc_remarks')
                ->label('UC remarks')
                ->placeholder('—')
                ->columnSpanFull(),
            TextEntry::make('sc_approvedBy.name')
                ->label('SC actioned by')
                ->placeholder('—'),
            TextEntry::make('sc_approved_at')
                ->label('SC actioned on')
                ->dateTime('M d, Y h:i A')
                ->placeholder('—'),
            TextEntry::make('sc_remarks')
                ->label('SC remarks')
                ->placeholder('—')
                ->columnSpanFull(),
            TextEntry::make('executed_at')
                ->label('Executed on')
                ->dateTime('M d, Y h:i A')
                ->placeholder('—'),
        ];
    }

    protected static function requestedItemsSection(): Section
    {
        return Section::make('Items')
            ->schema([
                RepeatableEntry::make('lines')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Item'),
                        TableColumn::make('Property No.'),
                        TableColumn::make('Issued'),
                        TableColumn::make('Qty'),
                    ])
                    ->schema([
                        TextEntry::make('issuance.item.name')
                            ->label('Item')
                            ->placeholder('—'),
                        TextEntry::make('asset_identifier')
                            ->label('Property No.')
                            ->state(function (\App\Models\PropertyActionRequestLine $record): string {
                                return $record->issuance?->property_number
                                    ?? $record->issuance?->reference_code
                                    ?? '—';
                            })
                            ->placeholder('—'),
                        TextEntry::make('issuance.issuance_date')
                            ->label('Issued')
                            ->date('M d, Y')
                            ->placeholder('—'),
                        TextEntry::make('issuance.quantity')
                            ->label('Qty')
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
