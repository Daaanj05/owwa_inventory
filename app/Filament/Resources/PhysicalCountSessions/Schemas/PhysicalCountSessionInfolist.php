<?php

namespace App\Filament\Resources\PhysicalCountSessions\Schemas;

use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Support\OwwaReferenceLabels;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PhysicalCountSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::sessionSection(),
                self::completionChecklistSection(),
                self::countProgressSection(),
                self::signatoriesSection(),
                self::linesSection(),
            ]);
    }

    /**
     * @return array<int, Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            self::completionChecklistSection(),
            self::signatoriesSection(),
            self::linesSection(),
        ];
    }

    public static function sessionSection(): Section
    {
        return Section::make('Session')
            ->columns(2)
            ->schema([
                TextEntry::make('reference_code')
                    ->label(fn (PhysicalCountSession $record): string => OwwaReferenceLabels::physicalCount($record->count_type)),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        PhysicalCountSession::STATUS_IN_PROGRESS => 'In progress',
                        PhysicalCountSession::STATUS_INCOMPLETE => 'Incomplete',
                        PhysicalCountSession::STATUS_COMPLETE => 'Complete',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        PhysicalCountSession::STATUS_COMPLETE => 'success',
                        PhysicalCountSession::STATUS_INCOMPLETE => 'warning',
                        default => 'gray',
                    }),
                TextEntry::make('count_type')->label('Form'),
                TextEntry::make('office.name')->label('Office'),
                TextEntry::make('count_date')->label('As at')->date(),
                TextEntry::make('completed_at')->label('Completed at')->dateTime()->placeholder('—'),
                TextEntry::make('inventory_type_label')->label('Inventory type')->columnSpanFull(),
                TextEntry::make('accountable_officer_name')->label('Accountable officer'),
                TextEntry::make('accountable_officer_designation')->label('Designation'),
            ]);
    }

    public static function completionChecklistSection(): Section
    {
        return Section::make('Completion checklist')
            ->visible(fn (PhysicalCountSession $record): bool => $record->supportsQrScanning())
            ->schema([
                TextEntry::make('missing_for_complete')
                    ->label('Missing for complete')
                    ->state(function (PhysicalCountSession $record): string {
                        $missing = $record->missingCompletionFields();
                        $summary = $record->countSummary();

                        $items = $missing;
                        if (! $record->hasBookListLoaded()) {
                            $items[] = 'load expected assets (book list)';
                        }
                        if ($summary['shortages'] > 0) {
                            $items[] = "{$summary['shortages']} shortage line(s)";
                        }

                        return $items === [] ? 'Ready to mark complete' : implode(', ', $items);
                    })
                    ->color(function (PhysicalCountSession $record): string {
                        $missing = $record->missingCompletionFields();
                        $summary = $record->countSummary();

                        return ($missing === [] && $summary['shortages'] === 0 && $record->hasBookListLoaded()) ? 'success' : 'warning';
                    })
                    ->columnSpanFull(),
            ]);
    }

    public static function countProgressSection(): Section
    {
        return Section::make('Count progress')
            ->description(fn (PhysicalCountSession $record): ?string => match (true) {
                ! $record->supportsQrScanning() => null,
                ! $record->hasBookListLoaded() => 'Scan-first mode — load expected assets on desktop to reconcile against the book list.',
                default => 'Compare scanned on-hand totals against book balances from inventory unit tags.',
            })
            ->columns(2)
            ->schema([
                TextEntry::make('scan_progress')
                    ->label('Scan progress')
                    ->state(function (PhysicalCountSession $record): string {
                        $summary = $record->countSummary();

                        if ($summary['scan_only'] ?? false) {
                            return "{$summary['scanned']} tag(s) scanned — load expected assets to reconcile";
                        }

                        $expected = $summary['expected'];

                        if ($expected === 0) {
                            return $record->supportsQrScanning()
                                ? 'No lines yet — scan on mobile or load expected assets'
                                : 'No count lines';
                        }

                        $percent = (int) round(($summary['scanned'] / $expected) * 100);

                        return "{$summary['scanned']} / {$expected} units ({$percent}%)";
                    })
                    ->color(function (PhysicalCountSession $record): string {
                        $summary = $record->countSummary();

                        if ($summary['expected'] === 0) {
                            return 'gray';
                        }

                        return $summary['scanned'] >= $summary['expected'] ? 'success' : 'warning';
                    })
                    ->columnSpanFull()
                    ->visible(fn (PhysicalCountSession $record): bool => $record->supportsQrScanning()),
                TextEntry::make('expected_units')
                    ->label('Expected (book)')
                    ->state(fn (PhysicalCountSession $record): int|string => ($record->countSummary()['scan_only'] ?? false)
                        ? '—'
                        : $record->countSummary()['expected']),
                TextEntry::make('scanned_units')
                    ->label(fn (PhysicalCountSession $record): string => ($record->countSummary()['scan_only'] ?? false)
                        ? 'Tags scanned'
                        : 'Scanned (on hand)')
                    ->state(fn (PhysicalCountSession $record): int => $record->countSummary()['scanned']),
                TextEntry::make('matched_lines')
                    ->label('Matched')
                    ->state(fn (PhysicalCountSession $record): int|string => ($record->countSummary()['scan_only'] ?? false)
                        ? '—'
                        : $record->countSummary()['matched'])
                    ->visible(fn (PhysicalCountSession $record): bool => $record->hasBookListLoaded()),
                TextEntry::make('shortage_lines')
                    ->label('Shortage lines')
                    ->state(fn (PhysicalCountSession $record): int => $record->countSummary()['shortages'])
                    ->color(fn (PhysicalCountSession $record): string => $record->countSummary()['shortages'] > 0 ? 'danger' : 'gray')
                    ->visible(fn (PhysicalCountSession $record): bool => $record->hasBookListLoaded()),
                TextEntry::make('overage_lines')
                    ->label('Overage lines')
                    ->state(fn (PhysicalCountSession $record): int => $record->countSummary()['overages'])
                    ->color(fn (PhysicalCountSession $record): string => $record->countSummary()['overages'] > 0 ? 'warning' : 'gray')
                    ->visible(fn (PhysicalCountSession $record): bool => $record->hasBookListLoaded()),
            ]);
    }

    public static function signatoriesSection(): Section
    {
        return Section::make('Signatories')
            ->columns(2)
            ->schema([
                TextEntry::make('accountable_officer_name')->label('Accountable officer')->placeholder('—'),
                TextEntry::make('accountable_officer_designation')->label('Designation')->placeholder('—'),
                TextEntry::make('certified_by_printed_name')->label('Certified by')->placeholder('—'),
                TextEntry::make('approved_by_printed_name')->label('Approved by')->placeholder('—'),
                TextEntry::make('verified_by_printed_name')->label('Verified by')->placeholder('—'),
            ]);
    }

    public static function linesSection(): Section
    {
        return Section::make('Lines')
            ->extraAttributes(['class' => 'owwa-pc-modal-lines'])
            ->schema([
                RepeatableEntry::make('lines')
                    ->state(fn (PhysicalCountSession $record) => $record->lines
                        ->sortBy(fn (PhysicalCountLine $line): int => match (true) {
                            $line->shortageOverageQuantity() < 0 => 0,
                            $line->shortageOverageQuantity() > 0 => 2,
                            default => 1,
                        }))
                    ->schema([
                        TextEntry::make('property_number')->label('Property no.'),
                        TextEntry::make('item.name')->label('Item'),
                        TextEntry::make('balance_per_card')->label('Per card'),
                        TextEntry::make('on_hand_count')->label('On hand'),
                        TextEntry::make('variance')
                            ->label('Shortage / overage')
                            ->state(fn (PhysicalCountLine $record): int => $record->shortageOverageQuantity())
                            ->color(fn (PhysicalCountLine $record): string => match (true) {
                                $record->shortageOverageQuantity() < 0 => 'danger',
                                $record->shortageOverageQuantity() > 0 => 'warning',
                                default => 'success',
                            }),
                        TextEntry::make('remarks')->label('Remarks')->columnSpanFull(),
                    ])
                    ->columns(5),
            ]);
    }
}
