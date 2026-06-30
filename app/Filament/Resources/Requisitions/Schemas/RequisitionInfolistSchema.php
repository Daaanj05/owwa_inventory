<?php

namespace App\Filament\Resources\Requisitions\Schemas;

use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Services\RequisitionFulfillmentService;
use App\Support\OwwaReferenceLabels;
use App\Support\RequisitionLineDisplay;
use App\Support\RequisitionStatus;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class RequisitionInfolistSchema
{
    /**
     * @return array<int, \Filament\Schemas\Components\Component|\Filament\Infolists\Components\Component>
     */
    public static function modalDetailSections(): array
    {
        return [
            Section::make('Requisition details')
                ->columns(2)
                ->schema([
                    TextEntry::make('related_issuances')
                        ->label('Related issuances')
                        ->state(fn (Requisition $record): ?string => RequisitionLineDisplay::relatedIssuancesText($record))
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('mixed_categories_notice')
                        ->label('')
                        ->state(fn (Requisition $record): ?string => $record->hasMixedCategories()
                            ? RequisitionLineDisplay::mixedCategoriesNotice()
                            : null)
                        ->visible(fn (Requisition $record): bool => $record->hasMixedCategories())
                        ->columnSpanFull(),
                    TextEntry::make('approvedBy.name')->label('Actioned by')->placeholder('—'),
                    TextEntry::make('approved_at')->label('Actioned on')->dateTime('M d, Y h:i A')->placeholder('—'),
                    TextEntry::make('purpose')
                        ->label('Purpose (RIS)')
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('remarks')
                        ->label('Rejection reason')
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->visible(fn (Requisition $record): bool => $record->status === Requisition::STATUS_REJECTED),
                ]),
            self::requestedItemsSection(),
        ];
    }

    public static function sections(): array
    {
        return [
            Section::make('Requisition details')
                ->columns(2)
                ->schema([
                    TextEntry::make('reference_code')->label(OwwaReferenceLabels::requisition()),
                    TextEntry::make('related_issuances')
                        ->label('Related issuances')
                        ->state(fn (Requisition $record): ?string => RequisitionLineDisplay::relatedIssuancesText($record))
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('mixed_categories_notice')
                        ->label('')
                        ->state(fn (Requisition $record): ?string => $record->hasMixedCategories()
                            ? RequisitionLineDisplay::mixedCategoriesNotice()
                            : null)
                        ->visible(fn (Requisition $record): bool => $record->hasMixedCategories())
                        ->columnSpanFull(),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => RequisitionStatus::label($state))
                        ->color(fn (?string $state): string => RequisitionStatus::color($state)),
                    TextEntry::make('requestedBy.name')->label('Requested by')->placeholder('—'),
                    TextEntry::make('created_at')->label('Date filed')->date('M d, Y'),
                    TextEntry::make('office.name')->label('Office')->placeholder('—'),
                    TextEntry::make('department.name')->label('Department')->placeholder('—'),
                    TextEntry::make('approvedBy.name')->label('Actioned by')->placeholder('—'),
                    TextEntry::make('approved_at')->label('Actioned on')->dateTime('M d, Y h:i A')->placeholder('—'),
                    TextEntry::make('purpose')
                        ->label('Purpose (RIS)')
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('remarks')
                        ->label('Rejection reason')
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->visible(fn (Requisition $record): bool => $record->status === Requisition::STATUS_REJECTED),
                ]),
            self::requestedItemsSection(),
        ];
    }

    public static function requestedItemsSection(): Section
    {
        return Section::make('Requested items')
            ->schema([
                RepeatableEntry::make('items')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Category'),
                        TableColumn::make('Item'),
                        TableColumn::make('Identifier'),
                        TableColumn::make('Requested'),
                        TableColumn::make('Issued'),
                        TableColumn::make('Remaining'),
                        TableColumn::make('Issue remarks'),
                    ])
                    ->schema([
                        TextEntry::make('item.category.name')
                            ->label('Category')
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('item.name')->label('Item')->placeholder('—'),
                        TextEntry::make('line_identifier')
                            ->label(fn (RequisitionItem $record): string => RequisitionLineDisplay::identifierLabel($record))
                            ->state(fn (RequisitionItem $record): string => RequisitionLineDisplay::identifierValue($record) ?? '—'),
                        TextEntry::make('quantity')->label('Requested'),
                        TextEntry::make('quantity_issued')->label('Issued')->placeholder('0'),
                        TextEntry::make('remaining_qty')
                            ->label('Remaining')
                            ->state(fn (RequisitionItem $record): int => app(RequisitionFulfillmentService::class)->remainingQuantity($record)),
                        TextEntry::make('issue_remarks')->label('Issue remarks')->placeholder('—'),
                    ]),
            ]);
    }

    public static function acceptIssueModalDescription(Requisition $record): string|Htmlable
    {
        $base = 'Issue items for RIS No. '.$record->reference_code.'. Quantities can be less than requested; use Issue remainder later for the balance.';

        if (! $record->hasMixedCategories()) {
            return $base;
        }

        return new HtmlString(
            e($base).'<br><br><span class="text-sm text-gray-600">'.e(RequisitionLineDisplay::mixedCategoriesNotice()).'</span>'
        );
    }
}
