<?php

namespace App\Filament\Resources\Acquisitions\Paperwork\Schemas;

use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\AcquisitionPaperwork;
use App\Support\AcquisitionPaperworkViewPresenter;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class AcquisitionPaperworkInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...self::prViewSections(),
            ]);
    }

    /**
     * @return array<int, Section>
     */
    public static function prViewSections(): array
    {
        return [
            Section::make('Purchase request')
                ->columns(2)
                ->schema([
                    TextEntry::make('pr_number')->label('PR No.')->placeholder('—'),
                    TextEntry::make('pr_status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (AcquisitionPaperwork $record): string => $record->phaseStatusLabel(AcquisitionPaperwork::PHASE_PR)),
                    TextEntry::make('pr_date')->label('PR date')->date('M d, Y')->placeholder('—'),
                    TextEntry::make('reference_code')->label('Case reference')->placeholder('—'),
                    TextEntry::make('itemCategory.name')->label('Category')->placeholder('—'),
                    TextEntry::make('office.name')->label('Office')->placeholder('—'),
                    TextEntry::make('requestingOffice.name')->label('Requesting office')->placeholder('—'),
                    TextEntry::make('purpose')->label('Purpose')->columnSpanFull()->placeholder('—'),
                    TextEntry::make('requested_by_name')->label('Requested by')->placeholder('—'),
                    TextEntry::make('approved_by_name')->label('Approved by')->placeholder('—'),
                ]),
            self::linkedRequisitionsSection(),
            Section::make('Line items')
                ->schema([
                    SchemaView::make('filament.resources.acquisitions.paperwork.partials.view-acquisition-items-table')
                        ->viewData(fn (AcquisitionPaperwork $record): array => [
                            'itemRows' => AcquisitionPaperworkViewPresenter::itemRows($record),
                            'showReceipts' => false,
                            'paperwork' => $record,
                        ]),
                ]),
        ];
    }

    /**
     * @return array<int, Section>
     */
    public static function receivedViewSections(): array
    {
        return [
            Section::make('Received acquisition')
                ->columns(3)
                ->schema([
                    TextEntry::make('pr_number')->label('PR No.')->placeholder('—'),
                    TextEntry::make('purchaseOrder.number')->label('PO No.')->placeholder('—'),
                    TextEntry::make('purchaseOrder.inspectionAcceptanceReport.number')->label('IAR No.')->placeholder('—'),
                    TextEntry::make('received_at')->label('Received at')->dateTime('M d, Y g:i A')->placeholder('—'),
                    TextEntry::make('office.name')->label('Office')->placeholder('—'),
                    TextEntry::make('itemCategory.name')->label('Category')->placeholder('—'),
                ]),
            Section::make('Received line items')
                ->schema([
                    SchemaView::make('filament.resources.acquisitions.paperwork.partials.view-acquisition-items-table')
                        ->viewData(fn (AcquisitionPaperwork $record): array => [
                            'itemRows' => AcquisitionPaperworkViewPresenter::receivedItemRows($record),
                            'showReceipts' => true,
                            'paperwork' => $record,
                            'forceShowCosts' => true,
                        ]),
                ]),
        ];
    }

    /**
     * @return array<int, Section>
     */
    public static function modalDetailSections(): array
    {
        return self::prViewSections();
    }

    public static function linkedRequisitionsSection(): Section
    {
        return Section::make('Linked requisitions')
            ->visible(fn (AcquisitionPaperwork $record): bool => $record->requisitions()->exists())
            ->schema([
                TextEntry::make('linked_requisitions')
                    ->label('See requisition')
                    ->html()
                    ->state(function (AcquisitionPaperwork $record): HtmlString {
                        $record->loadMissing('requisitions.office');

                        return new HtmlString($record->requisitions
                            ->map(function ($requisition): string {
                                $label = trim(($requisition->reference_code ?: "Requisition #{$requisition->id}")
                                    .' — '.($requisition->office?->name ?? 'No office'));

                                return sprintf(
                                    '<a class="font-medium text-primary-600 hover:underline" href="%s">%s</a>',
                                    e(RequisitionResource::viewModalUrl($requisition)),
                                    e($label),
                                );
                            })
                            ->implode('<br>'));
                    }),
            ]);
    }

    public static function itemsSection(): Section
    {
        return Section::make('Items')
            ->schema([
                SchemaView::make('filament.resources.acquisitions.paperwork.partials.view-acquisition-items-table')
                    ->viewData(fn (AcquisitionPaperwork $record): array => [
                        'itemRows' => AcquisitionPaperworkViewPresenter::itemRows($record),
                        'showReceipts' => false,
                        'paperwork' => $record,
                    ]),
            ]);
    }

    public static function paperworkFilesSection(): Section
    {
        return Section::make('PR / PO / IAR')
            ->description('Open each form to view details, export, or update approval status.')
            ->schema([
                TextEntry::make('pr_number')
                    ->label('PR')
                    ->formatStateUsing(fn (AcquisitionPaperwork $record): string => filled($record->pr_number)
                        ? $record->pr_number.' — '.$record->phaseStatusLabel(AcquisitionPaperwork::PHASE_PR)
                        : '— — '.$record->phaseStatusLabel(AcquisitionPaperwork::PHASE_PR)),
                TextEntry::make('po_number')
                    ->label('PO')
                    ->formatStateUsing(fn (AcquisitionPaperwork $record): string => filled($record->po_number)
                        ? $record->po_number.' — '.$record->phaseStatusLabel(AcquisitionPaperwork::PHASE_PO)
                        : '— — '.$record->phaseStatusLabel(AcquisitionPaperwork::PHASE_PO)),
                TextEntry::make('iar_number')
                    ->label('IAR')
                    ->formatStateUsing(fn (AcquisitionPaperwork $record): string => filled($record->iar_number)
                        ? $record->iar_number.' — '.$record->phaseStatusLabel(AcquisitionPaperwork::PHASE_IAR)
                        : '— — '.$record->phaseStatusLabel(AcquisitionPaperwork::PHASE_IAR)),
            ])
            ->columns(3);
    }

    public static function documentsSection(): Section
    {
        return self::paperworkFilesSection();
    }

    public static function prSection(): Section
    {
        return Section::make('Purchase request')
            ->columns(2)
            ->schema([
                TextEntry::make('pr_number')->label('PR No.')->placeholder('—'),
                TextEntry::make('pr_status')->label('Status')->formatStateUsing(fn (AcquisitionPaperwork $record): string => $record->phaseStatusLabel(AcquisitionPaperwork::PHASE_PR)),
                TextEntry::make('pr_date')->label('PR date')->date('M d, Y'),
                TextEntry::make('purpose')->label('Purpose')->columnSpanFull(),
                TextEntry::make('requested_by_name')->label('Requested by')->placeholder('—'),
                TextEntry::make('approved_by_name')->label('Approved by')->placeholder('—'),
                RepeatableEntry::make('lines')
                    ->label('Line items')
                    ->schema([
                        TextEntry::make('item.item_code')->label('Stock No.'),
                        TextEntry::make('description')->label('Description'),
                        TextEntry::make('quantity')->label('Qty'),
                        TextEntry::make('unit')->label('Unit'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function poSection(): Section
    {
        return Section::make('Purchase order (Appendix 61)')
            ->columns(2)
            ->schema([
                TextEntry::make('po_number')->label('PO No.')->placeholder('—'),
                TextEntry::make('po_status')->label('Status')->formatStateUsing(fn (AcquisitionPaperwork $record): string => $record->phaseStatusLabel(AcquisitionPaperwork::PHASE_PO)),
                TextEntry::make('supplier')->label('Supplier')->placeholder('—'),
                TextEntry::make('po_date')->label('PO date')->date('M d, Y'),
                RepeatableEntry::make('lines')
                    ->label('Line items')
                    ->schema([
                        TextEntry::make('item.item_code')->label('Stock No.'),
                        TextEntry::make('description')->label('Description'),
                        TextEntry::make('quantity')->label('Qty'),
                        TextEntry::make('unit_cost')->label('Unit cost')->money('PHP'),
                        TextEntry::make('amount')->label('Amount')->money('PHP'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function iarSection(): Section
    {
        return Section::make('Inspection & acceptance (Appendix 62)')
            ->columns(2)
            ->schema([
                TextEntry::make('iar_number')->label('IAR No.')->placeholder('—'),
                TextEntry::make('iar_status')->label('Status')->formatStateUsing(fn (AcquisitionPaperwork $record): string => $record->phaseStatusLabel(AcquisitionPaperwork::PHASE_IAR)),
                TextEntry::make('iar_date')->label('IAR date')->date('M d, Y'),
                TextEntry::make('inspection_officer_name')->label('Inspection officer')->placeholder('—'),
                TextEntry::make('custodian_name')->label('Supply/property custodian')->placeholder('—'),
                RepeatableEntry::make('lines')
                    ->label('Line items')
                    ->schema([
                        TextEntry::make('item.item_code')->label('Stock No.'),
                        TextEntry::make('description')->label('Description'),
                        TextEntry::make('quantity')->label('Qty'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
