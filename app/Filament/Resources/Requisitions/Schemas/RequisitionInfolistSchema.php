<?php

namespace App\Filament\Resources\Requisitions\Schemas;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Services\RequisitionFulfillmentService;
use App\Services\RequisitionRestockStatusService;
use App\Support\EmployeeRequisitionOriginalSubmission;
use App\Support\EmployeeRequisitionStatus;
use App\Support\OwwaReferenceLabels;
use App\Support\RequisitionLineDisplay;
use App\Support\RequisitionLineFulfillmentState;
use App\Support\RequisitionStatus;
use Filament\Facades\Filament;
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
                ->schema(self::detailFields(forModal: true)),
            self::relatedPurchaseRequestsSection(),
            self::employeeAllocationsSection(),
            self::requestedItemsSection(),
        ];
    }

    public static function sections(): array
    {
        return [
            Section::make('Requisition details')
                ->columns(2)
                ->schema(self::detailFields(forModal: false)),
            self::relatedPurchaseRequestsSection(),
            self::employeeAllocationsSection(),
            self::requestedItemsSection(),
        ];
    }

    protected static function relatedPurchaseRequestsSection(): Section
    {
        return Section::make('Related PRs')
            ->visible(fn (Requisition $record): bool => $record->acquisitionPaperworks()->exists())
            ->schema([
                TextEntry::make('related_purchase_requests')
                    ->hiddenLabel()
                    ->html()
                    ->state(function (Requisition $record): HtmlString {
                        $record->loadMissing('acquisitionPaperworks.itemCategory');

                        return new HtmlString($record->acquisitionPaperworks
                            ->map(function ($paperwork): string {
                                $label = $paperwork->pr_number
                                    ?: ($paperwork->reference_code ?: "PR #{$paperwork->id}");

                                return sprintf(
                                    '<a class="font-medium text-primary-600 hover:underline" href="%s">%s</a>',
                                    e(AcquisitionResource::viewModalUrl($paperwork, [
                                        'category' => $paperwork->item_category_id,
                                    ])),
                                    e($label),
                                );
                            })
                            ->implode('<br>'));
                    }),
            ]);
    }

    /**
     * @return array<int, TextEntry>
     */
    protected static function detailFields(bool $forModal): array
    {
        return [
            TextEntry::make('transaction_number')
                ->label(OwwaReferenceLabels::employeeRequisitionTransaction())
                ->placeholder('—')
                ->visible(fn (Requisition $record): bool => $record->isEmployeeRequest()),
            TextEntry::make('ris_number')
                ->label(OwwaReferenceLabels::requisition())
                ->state(fn (Requisition $record): ?string => $record->displayRisNumber())
                ->placeholder('—')
                ->visible(fn (Requisition $record): bool => $record->isEmployeeRequest()),
            TextEntry::make('reference_code')
                ->label(OwwaReferenceLabels::requisition())
                ->placeholder('—')
                ->visible(fn (Requisition $record): bool => ! $record->isEmployeeRequest()),
            TextEntry::make('employee_status')
                ->label('Status')
                ->badge()
                ->state(fn (Requisition $record): string => EmployeeRequisitionStatus::label($record))
                ->color(fn (Requisition $record): string => EmployeeRequisitionStatus::color($record))
                ->visible(fn (Requisition $record): bool => $record->isEmployeeRequest()),
            TextEntry::make('fulfillment_summary')
                ->label('Fulfillment')
                ->placeholder('—')
                ->visible(fn (Requisition $record): bool => $record->isEmployeeRequest() && filled($record->fulfillment_summary)),
            TextEntry::make('endorsed_at')
                ->label('Endorsed on')
                ->dateTime('M d, Y h:i A')
                ->placeholder('—')
                ->visible(fn (Requisition $record): bool => $record->isEmployeeRequest() && $record->endorsed_at !== null),
            TextEntry::make('closed_at')
                ->label('Closed on')
                ->dateTime('M d, Y h:i A')
                ->placeholder('—')
                ->visible(fn (Requisition $record): bool => $record->isEmployeeRequest() && $record->closed_at !== null),
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
                ->visible(fn (Requisition $record): bool => $record->hasMixedCategories()
                    && (Filament::auth()->user()?->isSupplyCustodian() ?? false))
                ->columnSpanFull(),
            TextEntry::make('status')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => RequisitionStatus::label($state))
                ->color(fn (?string $state): string => RequisitionStatus::color($state))
                ->visible(fn (Requisition $record): bool => ! $forModal && ! $record->isEmployeeRequest()),
            TextEntry::make('requestedBy.name')
                ->label('Requested by')
                ->placeholder('—')
                ->visible(fn (): bool => ! $forModal),
            TextEntry::make('created_at')
                ->label('Date filed')
                ->date('M d, Y')
                ->placeholder('—')
                ->visible(fn (): bool => ! $forModal),
            TextEntry::make('office.name')
                ->label('Office')
                ->placeholder('—')
                ->visible(fn (): bool => ! $forModal),
            TextEntry::make('department.name')
                ->label('Department')
                ->placeholder('—')
                ->visible(fn (): bool => ! $forModal),
            TextEntry::make('approvedBy.name')
                ->label('Actioned by')
                ->placeholder('—')
                ->state(function (Requisition $record): ?string {
                    if ($record->approved_at === null || $record->created_at === null) {
                        return $record->approvedBy?->name;
                    }

                    if ($record->approved_at->lt($record->created_at)) {
                        return null;
                    }

                    return $record->approvedBy?->name;
                }),
            TextEntry::make('approved_at')
                ->label('Actioned on')
                ->placeholder('—')
                ->state(function (Requisition $record): ?string {
                    if ($record->approved_at === null) {
                        return null;
                    }

                    if ($record->created_at !== null && $record->approved_at->lt($record->created_at)) {
                        return null;
                    }

                    return $record->approved_at->format('M d, Y h:i A');
                }),
            TextEntry::make('purpose')
                ->label(fn (Requisition $record): string => $record->isEmployeeRequest() && $record->compiled_into_requisition_id === null
                    ? 'Purpose'
                    : 'Purpose (RIS)')
                ->state(fn (Requisition $record): ?string => $record->isEmployeeRequest() && $record->compiled_into_requisition_id === null
                    ? $record->displayEmployeePurpose()
                    : $record->displayRisPurpose())
                ->placeholder('—')
                ->columnSpanFull(),
            TextEntry::make('original_purpose')
                ->label('Original purpose (at submit)')
                ->state(fn (Requisition $record): ?string => EmployeeRequisitionOriginalSubmission::originalPurpose($record))
                ->placeholder('—')
                ->columnSpanFull()
                ->visible(fn (Requisition $record): bool => $record->isEmployeeRequest()
                    && $record->hasEmployeeContentEdits()
                    && EmployeeRequisitionOriginalSubmission::originalPurpose($record) !== trim((string) ($record->purpose ?? ''))),
            TextEntry::make('content_edited_notice')
                ->label('')
                ->state(fn (Requisition $record): ?string => $record->hasEmployeeContentEdits()
                    ? 'Employee edited this request after submitting'
                        .($record->content_edited_at ? ' · '.$record->content_edited_at->format('M d, Y h:i A') : '')
                    : null)
                ->visible(fn (Requisition $record): bool => $record->isEmployeeRequest() && $record->hasEmployeeContentEdits())
                ->columnSpanFull(),
            TextEntry::make('remarks')
                ->label('Rejection reason')
                ->placeholder('—')
                ->columnSpanFull()
                ->visible(fn (Requisition $record): bool => $record->status === Requisition::STATUS_REJECTED),
        ];
    }

    public static function requestedItemsSection(): Section
    {
        return Section::make('Requested items')
            ->schema([
                self::employeeRequestedItemsRepeatable(),
                self::employeeOriginalVsCurrentSection(),
                self::consolidatedRequestedItemsRepeatable(),
            ]);
    }

    protected static function employeeOriginalVsCurrentSection(): Section
    {
        return Section::make('Original vs current')
            ->description('What the employee submitted first, compared with their latest edits.')
            ->visible(fn (Requisition $record): bool => $record->isEmployeeRequest() && $record->hasEmployeeContentEdits())
            ->schema([
                TextEntry::make('original_vs_current_lines')
                    ->hiddenLabel()
                    ->state(function (Requisition $record): HtmlString {
                        $rows = EmployeeRequisitionOriginalSubmission::lineComparisonRows($record);

                        if ($rows === []) {
                            return new HtmlString('<p class="text-sm text-gray-500">No line differences.</p>');
                        }

                        $body = collect($rows)
                            ->map(function (array $row): string {
                                $current = $row['current_quantity'] === null ? '—' : (string) $row['current_quantity'];
                                $badge = match ($row['change']) {
                                    'added' => 'Added',
                                    'removed' => 'Removed',
                                    'changed' => 'Changed',
                                    default => 'Same',
                                };

                                return sprintf(
                                    '<tr><td>%s</td><td class="text-right">%d</td><td class="text-right">%s</td><td>%s</td></tr>',
                                    e($row['item_name']),
                                    $row['original_quantity'],
                                    e($current),
                                    e($badge),
                                );
                            })
                            ->implode('');

                        return new HtmlString(
                            '<div class="overflow-x-auto"><table class="w-full text-sm">'
                            .'<thead><tr>'
                            .'<th class="text-left font-medium">Item</th>'
                            .'<th class="text-right font-medium">Original qty</th>'
                            .'<th class="text-right font-medium">Current qty</th>'
                            .'<th class="text-left font-medium">Change</th>'
                            .'</tr></thead><tbody>'.$body.'</tbody></table></div>'
                        );
                    })
                    ->columnSpanFull(),
            ]);
    }

    protected static function employeeAllocationsSection(): Section
    {
        return Section::make('Employee allocations')
            ->description('Per-employee breakdown of endorsed quantities on this consolidated RIS.')
            ->visible(fn (Requisition $record): bool => ! $record->isEmployeeRequest()
                && $record->sourceEndorsements()->exists())
            ->schema([
                RepeatableEntry::make('sourceEndorsements')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Employee'),
                        TableColumn::make('Transaction'),
                        TableColumn::make('Department'),
                        TableColumn::make('Item'),
                        TableColumn::make('Requested'),
                        TableColumn::make('Endorsed'),
                        TableColumn::make('Source purpose'),
                        TableColumn::make('UC remarks'),
                    ])
                    ->schema([
                        TextEntry::make('requestedBy.name')->label('Employee')->placeholder('—'),
                        TextEntry::make('sourceRequisition.transaction_number')
                            ->label('Transaction')
                            ->placeholder('—'),
                        TextEntry::make('sourceRequisition.department.name')
                            ->label('Department')
                            ->placeholder('—'),
                        TextEntry::make('item.name')->label('Item')->placeholder('—'),
                        TextEntry::make('requested_quantity')->label('Requested'),
                        TextEntry::make('endorsed_quantity')->label('Endorsed'),
                        TextEntry::make('sourceRequisition.purpose')
                            ->label('Source purpose')
                            ->placeholder('—'),
                        TextEntry::make('employee_remarks')
                            ->label('UC remarks')
                            ->placeholder('—'),
                    ]),
            ]);
    }

    protected static function employeeRequestedItemsRepeatable(): RepeatableEntry
    {
        return RepeatableEntry::make('items')
            ->hiddenLabel()
            ->visible(fn (Requisition $record): bool => $record->isEmployeeRequest())
            ->table(fn (Requisition $record): array => $record->endorsed_at !== null
                ? [
                    TableColumn::make('Category'),
                    TableColumn::make('Item'),
                    TableColumn::make('Restock'),
                    TableColumn::make(OwwaReferenceLabels::assetIdentifierTableHeader()),
                    TableColumn::make('Requested'),
                    TableColumn::make('Endorsed by UC'),
                    TableColumn::make('Status'),
                    TableColumn::make('UC remarks'),
                ]
                : [
                    TableColumn::make('Category'),
                    TableColumn::make('Item'),
                    TableColumn::make('Restock'),
                    TableColumn::make(OwwaReferenceLabels::assetIdentifierTableHeader()),
                    TableColumn::make('Qty'),
                    TableColumn::make('Status'),
                ])
            ->schema(fn (Requisition $record): array => self::employeeItemFields($record));
    }

    /**
     * @return array<int, TextEntry>
     */
    protected static function employeeItemFields(Requisition $requisition): array
    {
        $endorsed = $requisition->endorsed_at !== null;

        $fields = [
            TextEntry::make('item.category.name')
                ->label('Category')
                ->badge()
                ->placeholder('—'),
            TextEntry::make('item.name')->label('Item')->placeholder('—'),
            self::restockStatusEntry(),
            TextEntry::make('line_identifier')
                ->label(fn (RequisitionItem $record): string => RequisitionLineDisplay::identifierLabel($record))
                ->state(fn (RequisitionItem $record): string => RequisitionLineDisplay::identifierValue($record) ?? '—'),
            TextEntry::make('quantity')->label($endorsed ? 'Requested' : 'Qty'),
        ];

        if ($endorsed) {
            $fields[] = TextEntry::make('endorsed_quantity')
                ->label('Endorsed by UC')
                ->state(function (RequisitionItem $record) use ($requisition): ?int {
                    return $requisition->employeeLineEndorsement((int) $record->id)?->endorsed_quantity;
                })
                ->placeholder('—');
        }

        $fields[] = TextEntry::make('fulfillment_state')
            ->label('Status')
            ->badge()
            ->state(fn (RequisitionItem $record): string => $record->fulfillmentStateLabel())
            ->color(fn (RequisitionItem $record): string => RequisitionLineFulfillmentState::color($record->fulfillmentState()));

        if ($endorsed) {
            $fields[] = TextEntry::make('employee_endorsement_remarks')
                ->label('UC remarks')
                ->state(function (RequisitionItem $record) use ($requisition): ?string {
                    return $requisition->employeeLineEndorsement((int) $record->id)?->employee_remarks;
                })
                ->placeholder('—');
        }

        return $fields;
    }

    protected static function consolidatedRequestedItemsRepeatable(): RepeatableEntry
    {
        return RepeatableEntry::make('items')
            ->hiddenLabel()
            ->visible(fn (Requisition $record): bool => ! $record->isEmployeeRequest())
            ->table([
                TableColumn::make('Category'),
                TableColumn::make('Item'),
                TableColumn::make('Restock'),
                TableColumn::make(OwwaReferenceLabels::assetIdentifierTableHeader()),
                TableColumn::make('Requested'),
                TableColumn::make('Status'),
                TableColumn::make('Issued'),
                TableColumn::make('Remaining'),
                TableColumn::make('Remarks'),
            ])
            ->schema(self::sharedItemFields(
                quantityLabel: 'Requested',
                includeIssued: true,
                includeRemaining: true,
            ));
    }

    /**
     * @return array<int, TextEntry>
     */
    protected static function sharedItemFields(
        string $quantityLabel,
        bool $includeIssued,
        bool $includeRemaining,
    ): array {
        $fields = [
            TextEntry::make('item.category.name')
                ->label('Category')
                ->badge()
                ->placeholder('—'),
            TextEntry::make('item.name')->label('Item')->placeholder('—'),
            self::restockStatusEntry(),
            TextEntry::make('line_identifier')
                ->label(fn (RequisitionItem $record): string => RequisitionLineDisplay::identifierLabel($record))
                ->state(fn (RequisitionItem $record): string => RequisitionLineDisplay::identifierValue($record) ?? '—'),
            TextEntry::make('quantity')->label($quantityLabel),
            TextEntry::make('fulfillment_state')
                ->label('Status')
                ->badge()
                ->state(fn (RequisitionItem $record): string => $record->fulfillmentStateLabel())
                ->color(fn (RequisitionItem $record): string => RequisitionLineFulfillmentState::color($record->fulfillmentState())),
        ];

        if ($includeIssued) {
            $fields[] = TextEntry::make('quantity_issued')->label('Issued')->placeholder('0');
        }

        if ($includeRemaining) {
            $fields[] = TextEntry::make('remaining_qty')
                ->label('Remaining')
                ->state(fn (RequisitionItem $record): int => app(RequisitionFulfillmentService::class)->remainingQuantity($record));
        }

        $fields[] = TextEntry::make('line_remarks')
            ->label('Remarks')
            ->state(fn (RequisitionItem $record): ?string => $record->issue_remarks)
            ->placeholder('—');

        return $fields;
    }

    protected static function restockStatusEntry(): TextEntry
    {
        return TextEntry::make('restock_status')
            ->label('Restock')
            ->state(function (RequisitionItem $record): ?string {
                $statusService = app(RequisitionRestockStatusService::class);
                $status = $statusService->resolve((int) $record->item_id);

                return $statusService->displayLabel($status);
            })
            ->badge()
            ->color(fn (?string $state): string => filled($state) && str_starts_with((string) $state, 'Inactive') ? 'warning' : 'success')
            ->placeholder('Active');
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
