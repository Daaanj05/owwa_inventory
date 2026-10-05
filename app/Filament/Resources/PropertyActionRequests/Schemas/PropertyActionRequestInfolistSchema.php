<?php

namespace App\Filament\Resources\PropertyActionRequests\Schemas;

use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\Transfers\TransferResource;
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
            self::outcomeLinksSection(),
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
                ->placeholder('—')
                ->visible(self::hideOnEmployeeRequest()),
            TextEntry::make('action_type')
                ->label('Action')
                ->formatStateUsing(fn (PropertyActionRequest $record): string => $record->actionTypeLabel())
                ->placeholder('—')
                ->visible(self::hideOnEmployeeRequest()),
            TextEntry::make('reason_code')
                ->label('Reason')
                ->formatStateUsing(fn (PropertyActionRequest $record): string => $record->reasonLabel())
                ->placeholder('—')
                ->visible(self::hideOnEmployeeRequest()),
            TextEntry::make('reason_detail')
                ->label('Details')
                ->placeholder('—')
                ->columnSpanFull(),
            TextEntry::make('category')
                ->label('Category')
                ->state(function (PropertyActionRequest $record): string {
                    $record->loadMissing('lines.issuance.item.category');

                    $categories = $record->lines
                        ->map(fn ($line) => $line->issuance?->item?->category?->name)
                        ->filter()
                        ->unique()
                        ->values();

                    if ($categories->isEmpty()) {
                        return '—';
                    }

                    return $categories->implode(', ');
                })
                ->placeholder('—'),
            TextEntry::make('requestedBy.name')
                ->label('Requested by')
                ->placeholder('—')
                ->visible(self::hideOnEmployeeRequest()),
            TextEntry::make('accountableUser.name')
                ->label('Accountable UC')
                ->placeholder('—')
                ->visible(self::hideOnEmployeeRequest()),
            TextEntry::make('office.name')
                ->label('Office')
                ->placeholder('—')
                ->visible(self::hideOnEmployeeRequest()),
            TextEntry::make('department.name')
                ->label('Department')
                ->placeholder('—')
                ->visible(self::hideOnEmployeeRequest()),
            TextEntry::make('created_at')
                ->label('Date filed')
                ->date('M d, Y')
                ->placeholder('—')
                ->visible(self::hideOnEmployeeRequest()),
            TextEntry::make('status')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (PropertyActionRequest $record): string => $record->statusLabel())
                ->color(fn (?string $state): string => match ($state) {
                    PropertyActionRequest::STATUS_APPROVED, PropertyActionRequest::STATUS_EXECUTED => 'success',
                    PropertyActionRequest::STATUS_REJECTED => 'danger',
                    PropertyActionRequest::STATUS_PENDING_UC, PropertyActionRequest::STATUS_PENDING_SC => 'warning',
                    default => 'gray',
                })
                ->visible(self::hideOnEmployeeRequest()),
            TextEntry::make('uc_approvedBy.name')
                ->label('UC actioned by')
                ->placeholder('—')
                ->visible(fn (PropertyActionRequest $record): bool => self::showApprovalField($record, filled($record->uc_approved_by))),
            TextEntry::make('uc_approved_at')
                ->label('UC actioned on')
                ->dateTime('M d, Y h:i A')
                ->placeholder('—')
                ->visible(fn (PropertyActionRequest $record): bool => self::showApprovalField($record, $record->uc_approved_at !== null)),
            TextEntry::make('uc_remarks')
                ->label('UC remarks')
                ->placeholder('—')
                ->columnSpanFull()
                ->visible(fn (PropertyActionRequest $record): bool => self::showApprovalField($record, filled($record->uc_remarks))),
            TextEntry::make('sc_approvedBy.name')
                ->label('SC actioned by')
                ->placeholder('—')
                ->visible(fn (PropertyActionRequest $record): bool => self::showApprovalField($record, filled($record->sc_approved_by))),
            TextEntry::make('sc_approved_at')
                ->label('SC actioned on')
                ->dateTime('M d, Y h:i A')
                ->placeholder('—')
                ->visible(fn (PropertyActionRequest $record): bool => self::showApprovalField($record, $record->sc_approved_at !== null)),
            TextEntry::make('sc_remarks')
                ->label('SC remarks')
                ->placeholder('—')
                ->columnSpanFull()
                ->visible(fn (PropertyActionRequest $record): bool => self::showApprovalField($record, filled($record->sc_remarks))),
            TextEntry::make('executed_at')
                ->label('Received & routed on')
                ->dateTime('M d, Y h:i A')
                ->placeholder('—')
                ->visible(fn (PropertyActionRequest $record): bool => self::showApprovalField($record, $record->executed_at !== null)),
        ];
    }

    protected static function hideOnEmployeeRequest(): \Closure
    {
        return fn (PropertyActionRequest $record): bool => ! $record->isEmployeeRequest();
    }

    protected static function showApprovalField(PropertyActionRequest $record, bool $filled): bool
    {
        if (! $record->isEmployeeRequest()) {
            return true;
        }

        return $filled;
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
                        TextEntry::make('quantity')
                            ->label('Qty')
                            ->placeholder('—'),
                    ]),
            ]);
    }

    protected static function outcomeLinksSection(): Section
    {
        return Section::make('Routed records')
            ->columns(2)
            ->visible(fn (PropertyActionRequest $record): bool => $record->status === PropertyActionRequest::STATUS_EXECUTED
                && ($record->linkedDisposalId() !== null || $record->linkedTransferId() !== null))
            ->schema([
                TextEntry::make('disposal_link')
                    ->label('Disposal')
                    ->state('Open Disposal')
                    ->url(function (PropertyActionRequest $record): ?string {
                        $id = $record->linkedDisposalId();

                        return $id ? DisposalResource::getUrl('view', ['record' => $id]) : null;
                    })
                    ->openUrlInNewTab()
                    ->visible(fn (PropertyActionRequest $record): bool => $record->linkedDisposalId() !== null),
                TextEntry::make('transfer_link')
                    ->label('Transfer')
                    ->state('Open Transfer')
                    ->url(function (PropertyActionRequest $record): ?string {
                        $id = $record->linkedTransferId();

                        return $id ? TransferResource::getUrl('view', ['record' => $id]) : null;
                    })
                    ->openUrlInNewTab()
                    ->visible(fn (PropertyActionRequest $record): bool => $record->linkedTransferId() !== null),
            ]);
    }
}
