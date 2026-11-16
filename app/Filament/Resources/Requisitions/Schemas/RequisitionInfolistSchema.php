<?php

namespace App\Filament\Resources\Requisitions\Schemas;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\RequisitionSourceEndorsement;
use App\Services\RequisitionFulfillmentService;
use App\Services\RequisitionStockSnapshotService;
use App\Support\EmployeeRequisitionOriginalSubmission;
use App\Support\EmployeeRequisitionStatus;
use App\Support\InventoryCategoryOptions;
use App\Support\OwwaReferenceLabels;
use App\Support\RequisitionLineDisplay;
use App\Support\RequisitionLineFulfillmentState;
use App\Support\RequisitionStatus;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Section;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
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
            self::printedSignatoriesSection(),
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
            self::printedSignatoriesSection(),
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

    protected static function printedSignatoriesSection(): Section
    {
        return Section::make('Printed RIS signatories')
            ->description('Names printed on Appendix 63. Separate from Processed by.')
            ->columns(2)
            ->visible(fn (Requisition $record): bool => filled($record->ris_approved_by_name)
                || filled($record->ris_requested_by_name)
                || filled($record->ris_issued_by_name)
                || filled($record->ris_received_by_name))
            ->schema([
                self::printedSignatoryEntry('Requested by', 'ris_requested_by'),
                self::printedSignatoryEntry('Approved by', 'ris_approved_by'),
                self::printedSignatoryEntry('Issued by', 'ris_issued_by'),
                self::printedSignatoryEntry('Received by', 'ris_received_by'),
            ]);
    }

    protected static function printedSignatoryEntry(string $label, string $prefix): TextEntry
    {
        return TextEntry::make($prefix.'_block')
            ->label($label)
            ->state(function (Requisition $record) use ($prefix): ?string {
                $name = $record->{$prefix.'_name'} ?? null;
                if (blank($name)) {
                    return null;
                }

                $designation = $record->{$prefix.'_designation'} ?? null;
                $date = $record->{$prefix.'_date'} ?? null;
                $dateText = $date?->format('M d, Y');

                return collect([$name, $designation, $dateText])
                    ->filter(fn (?string $part): bool => filled($part))
                    ->implode(' · ');
            })
            ->placeholder('—')
            ->visible(function (Requisition $record) use ($prefix): bool {
                $name = $record->{$prefix.'_name'} ?? null;

                return filled($name);
            });
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
                ->columnSpanFull()
                ->visible(fn (Requisition $record): bool => RequisitionLineDisplay::hasIssuances($record)),
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
            TextEntry::make('purpose')
                ->label(fn (Requisition $record): string => $record->isEmployeeRequest() && $record->compiled_into_requisition_id === null
                    ? 'Purpose'
                    : 'Purpose (RIS)')
                ->state(fn (Requisition $record): ?string => $record->isEmployeeRequest() && $record->compiled_into_requisition_id === null
                    ? $record->displayEmployeePurpose()
                    : $record->displayRisPurpose())
                ->placeholder('—')
                ->columnSpanFull(),
            TextEntry::make('approvedBy.name')
                ->label('Processed by')
                ->placeholder('—')
                ->state(fn (Requisition $record): ?string => self::processedByName($record))
                ->visible(fn (Requisition $record): bool => filled(self::processedByName($record))),
            TextEntry::make('approved_at')
                ->label('Date processed')
                ->placeholder('—')
                ->state(fn (Requisition $record): ?string => self::processedAtText($record))
                ->visible(fn (Requisition $record): bool => filled(self::processedAtText($record))),
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

    protected static function processedByName(Requisition $record): ?string
    {
        if ($record->approved_at === null || $record->created_at === null) {
            return $record->approvedBy?->name;
        }

        if ($record->approved_at->lt($record->created_at)) {
            return null;
        }

        return $record->approvedBy?->name;
    }

    protected static function processedAtText(Requisition $record): ?string
    {
        if ($record->approved_at === null) {
            return null;
        }

        if ($record->created_at !== null && $record->approved_at->lt($record->created_at)) {
            return null;
        }

        return $record->approved_at->format('M d, Y h:i A');
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
            ->description('Click an employee row to see their endorsed lines. The requisition view stays open underneath.')
            ->visible(fn (Requisition $record): bool => ! $record->isEmployeeRequest()
                && $record->sourceEndorsements()->exists())
            ->schema([
                SchemaActions::make(fn (Requisition $record): array => collect(self::employeeAllocationGroups($record))
                    ->map(fn (array $group): Action => self::viewEmployeeAllocationAction($group))
                    ->all())
                    ->extraAttributes(['class' => 'owwa-employee-allocation-actions'])
                    ->fullWidth(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $group
     */
    protected static function viewEmployeeAllocationAction(array $group): Action
    {
        $employeeUserId = (int) ($group['employee_user_id'] ?? 0);
        $employeeName = (string) ($group['employee_name'] ?? 'Employee');
        $department = (string) ($group['department_name'] ?? '—');
        $lineCount = (int) ($group['line_count'] ?? 0);

        return Action::make('viewEmployeeAllocation_'.$employeeUserId)
            ->label(new HtmlString(
                '<span class="owwa-employee-allocation-row__main">'
                .'<span class="owwa-employee-allocation-row__name">'.e($employeeName).'</span>'
                .'<span class="owwa-employee-allocation-row__meta">'.e($department).'</span>'
                .'</span>'
                .'<span class="owwa-employee-allocation-row__aside">'
                .'<span class="owwa-employee-allocation-row__lines">'.$lineCount.' '.($lineCount === 1 ? 'line' : 'lines').'</span>'
                .'<span class="owwa-employee-allocation-row__hint" aria-hidden="true">Open</span>'
                .'</span>'
            ))
            ->extraAttributes([
                'class' => 'owwa-employee-allocation-row',
                'title' => 'Open endorsed items for '.$employeeName,
            ])
            ->color('gray')
            ->arguments([
                'employee_user_id' => $employeeUserId,
                'employee_name' => $employeeName,
            ])
            ->overlayParentActions()
            ->modalHeading(fn (array $arguments): string => ((string) ($arguments['employee_name'] ?? $employeeName)).' — endorsed items')
            ->modalWidth(OwwaFormModalDefaults::WIDTH_WIDE)
            ->extraModalWindowAttributes(['class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS], merge: true)
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema(fn (array $arguments, ?Requisition $record): array => [
                RepeatableEntry::make('lines')
                    ->hiddenLabel()
                    ->extraAttributes(['class' => 'owwa-requisition-items-table'])
                    ->getStateUsing(function () use ($arguments, $record): array {
                        if (! $record instanceof Requisition) {
                            return [];
                        }

                        $userId = (int) ($arguments['employee_user_id'] ?? 0);
                        $group = collect(self::employeeAllocationGroups($record))
                            ->firstWhere('employee_user_id', $userId);

                        return is_array($group['lines'] ?? null) ? $group['lines'] : [];
                    })
                    ->table([
                        TableColumn::make(OwwaReferenceLabels::TRANSACTION_NO),
                        TableColumn::make('Category'),
                        TableColumn::make('Item'),
                        TableColumn::make(OwwaReferenceLabels::STOCK_NO),
                        TableColumn::make('Requested'),
                        TableColumn::make('Endorsed'),
                        TableColumn::make('Source purpose'),
                        TableColumn::make('Endorsement note'),
                    ])
                    ->schema([
                        TextEntry::make('transaction_number')
                            ->label(OwwaReferenceLabels::employeeRequisitionTransaction())
                            ->placeholder('—'),
                        TextEntry::make('category_name')
                            ->label('Category')
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('item_name')
                            ->label('Item')
                            ->placeholder('—'),
                        TextEntry::make('identifier')
                            ->label(OwwaReferenceLabels::STOCK_NO)
                            ->placeholder('—'),
                        TextEntry::make('requested_quantity')->label('Requested'),
                        TextEntry::make('endorsed_quantity')->label('Endorsed'),
                        TextEntry::make('purpose')
                            ->label('Source purpose')
                            ->placeholder('—'),
                        TextEntry::make('employee_remarks')
                            ->label('Endorsement note')
                            ->placeholder('—'),
                    ]),
            ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function employeeAllocationGroups(Requisition $record): array
    {
        $record->loadMissing([
            'sourceEndorsements.requestedBy',
            'sourceEndorsements.sourceRequisition.department',
            'sourceEndorsements.item.category',
            'sourceEndorsements.requisitionItem.item.category',
            'sourceEndorsements.requisitionItem.requisition',
        ]);

        return $record->sourceEndorsements
            ->groupBy(fn (RequisitionSourceEndorsement $endorsement): int => (int) ($endorsement->requested_by_user_id ?? 0))
            ->map(function (Collection $lines): array {
                /** @var RequisitionSourceEndorsement|null $first */
                $first = $lines->first();
                $sortedLines = self::sortEndorsementLinesForDisplay($lines);

                return [
                    'employee_user_id' => (int) ($first?->requested_by_user_id ?? 0),
                    'employee_name' => $first?->requestedBy?->name ?? '—',
                    'department_name' => $first?->sourceRequisition?->department?->name ?? '—',
                    'line_count' => $sortedLines->count(),
                    'lines' => $sortedLines
                        ->map(fn (RequisitionSourceEndorsement $endorsement): array => self::endorsementLineState($endorsement))
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy(fn (array $group): string => mb_strtolower((string) $group['employee_name']), SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, RequisitionSourceEndorsement>  $lines
     * @return Collection<int, RequisitionSourceEndorsement>
     */
    protected static function sortEndorsementLinesForDisplay(Collection $lines): Collection
    {
        return $lines
            ->sort(function (RequisitionSourceEndorsement $left, RequisitionSourceEndorsement $right): int {
                $left->loadMissing(['item.category', 'requisitionItem.item.category', 'requisitionItem.requisition']);
                $right->loadMissing(['item.category', 'requisitionItem.item.category', 'requisitionItem.requisition']);

                $categoryCompare = InventoryCategoryOptions::sortRankForCategory($left->item?->category)
                    <=> InventoryCategoryOptions::sortRankForCategory($right->item?->category);

                if ($categoryCompare !== 0) {
                    return $categoryCompare;
                }

                $nameCompare = strcasecmp((string) ($left->item?->name ?? ''), (string) ($right->item?->name ?? ''));

                if ($nameCompare !== 0) {
                    return $nameCompare;
                }

                return RequisitionLineDisplay::compareStockNumberKeys(
                    self::endorsementIdentifier($left),
                    self::endorsementIdentifier($right),
                );
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected static function endorsementLineState(RequisitionSourceEndorsement $endorsement): array
    {
        return [
            'transaction_number' => $endorsement->sourceRequisition?->transaction_number,
            'category_name' => $endorsement->item?->category?->name,
            'item_name' => $endorsement->item?->name,
            'identifier' => self::endorsementIdentifier($endorsement),
            'requested_quantity' => $endorsement->requested_quantity,
            'endorsed_quantity' => $endorsement->endorsed_quantity,
            'purpose' => $endorsement->sourceRequisition?->purpose,
            'employee_remarks' => $endorsement->employee_remarks,
        ];
    }

    protected static function endorsementIdentifier(RequisitionSourceEndorsement $endorsement): ?string
    {
        if ($endorsement->requisitionItem instanceof RequisitionItem) {
            return RequisitionLineDisplay::identifierValue($endorsement->requisitionItem);
        }

        $endorsement->loadMissing('item.category');

        return OwwaReferenceLabels::assetIdentifierValue(
            $endorsement->item?->category?->getTemplateSlug(),
            null,
            $endorsement->item?->item_code,
        );
    }

    protected static function employeeRequestedItemsRepeatable(): RepeatableEntry
    {
        return RepeatableEntry::make('items')
            ->hiddenLabel()
            ->visible(fn (Requisition $record): bool => $record->isEmployeeRequest())
            ->getStateUsing(fn (Requisition $record): array => self::sortedRequestedItems($record))
            ->table(fn (Requisition $record): array => $record->endorsed_at !== null
                ? [
                    TableColumn::make('Category'),
                    TableColumn::make('Item'),
                    TableColumn::make(self::identifierHeaderForRequisition($record)),
                    TableColumn::make('Requested'),
                    TableColumn::make('Endorsed by UC'),
                    TableColumn::make('Status'),
                    TableColumn::make('Endorsement note'),
                ]
                : [
                    TableColumn::make('Category'),
                    TableColumn::make('Item'),
                    TableColumn::make(self::identifierHeaderForRequisition($record)),
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
                ->label('Endorsement note')
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
            ->extraAttributes(['class' => 'owwa-requisition-items-table'])
            ->getStateUsing(fn (Requisition $record): array => self::sortedRequestedItems($record))
            ->table(fn (Requisition $record): array => [
                TableColumn::make('Category'),
                TableColumn::make('Item'),
                TableColumn::make(self::identifierHeaderForRequisition($record)),
                TableColumn::make('Available stock'),
                TableColumn::make('Requested'),
                TableColumn::make('Issued'),
                TableColumn::make('Remaining'),
                TableColumn::make('Status'),
                TableColumn::make('Issue remarks'),
            ])
            ->schema(self::sharedItemFields(
                quantityLabel: 'Requested',
                includeIssued: true,
                includeRemaining: true,
            ));
    }

    /**
     * @return array<int, RequisitionItem>
     */
    protected static function sortedRequestedItems(Requisition $record): array
    {
        $record->loadMissing('items.item.category');

        return RequisitionLineDisplay::sortLinesForDisplay($record->items)->all();
    }

    protected static function identifierHeaderForRequisition(Requisition $record): string
    {
        $record->loadMissing('items.item.category');

        return OwwaReferenceLabels::assetIdentifierTableHeaderForSlugs(
            $record->items->map(fn (RequisitionItem $line): ?string => $line->item?->category?->getTemplateSlug())
        );
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
            TextEntry::make('line_identifier')
                ->label(fn (RequisitionItem $record): string => RequisitionLineDisplay::identifierLabel($record))
                ->state(fn (RequisitionItem $record): string => RequisitionLineDisplay::identifierValue($record) ?? '—'),
            TextEntry::make('available_stock')
                ->label('Available stock')
                ->state(fn (RequisitionItem $record): int => app(RequisitionStockSnapshotService::class)->regionalStockForItem((int) $record->item_id)),
            TextEntry::make('quantity')->label($quantityLabel),
        ];

        if ($includeIssued) {
            $fields[] = TextEntry::make('quantity_issued')->label('Issued')->placeholder('0');
        }

        if ($includeRemaining) {
            $fields[] = TextEntry::make('remaining_qty')
                ->label('Remaining')
                ->state(fn (RequisitionItem $record): int => app(RequisitionFulfillmentService::class)->remainingQuantity($record));
        }

        $fields[] = TextEntry::make('fulfillment_state')
            ->label('Status')
            ->badge()
            ->state(fn (RequisitionItem $record): string => $record->fulfillmentStateLabel())
            ->color(fn (RequisitionItem $record): string => RequisitionLineFulfillmentState::color($record->fulfillmentState()));
        $fields[] = TextEntry::make('line_remarks')
            ->label('Issue remarks')
            ->state(fn (RequisitionItem $record): ?string => $record->issue_remarks)
            ->placeholder('—');

        return $fields;
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
