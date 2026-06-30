<?php

namespace App\Support;

use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserLog;

class OwwaTransactionViewPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function forAcquisition(Acquisition $record): array
    {
        $record->loadMissing(['item', 'office']);

        $hero = OwwaRecordHeroData::make(
            reference: $record->reference_code ?? '—',
            statusLabel: 'Received',
            statusClass: 'owwa-pc-status-badge--complete',
            meta: [
                ['label' => 'Item', 'value' => $record->item?->name ?? '—'],
                ['label' => 'Date', 'value' => $record->acquisition_date?->format('M j, Y') ?? '—'],
                ['label' => 'Office', 'value' => $record->office?->name ?? '—'],
            ],
            kpis: [
                ['label' => 'Quantity', 'value' => (string) $record->quantity],
                ['label' => 'Unit cost', 'value' => '₱'.number_format((float) $record->unit_cost, 2)],
            ],
        );
        $hero['referenceLabel'] = 'Reference';

        return $hero;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forIssuance(Issuance $record): array
    {
        $record->loadMissing(['item', 'office', 'department', 'requisition']);

        $hero = OwwaRecordHeroData::make(
            reference: $record->reference_code ?? '—',
            statusLabel: 'Issued',
            statusClass: 'owwa-pc-status-badge--complete',
            meta: [
                ['label' => 'Item', 'value' => $record->item?->name ?? '—'],
                ['label' => 'Date', 'value' => $record->issuance_date?->format('M j, Y') ?? '—'],
                ['label' => 'RIS ref.', 'value' => $record->requisition?->reference_code ?? '—'],
            ],
            kpis: [
                ['label' => 'Quantity', 'value' => (string) $record->quantity],
                ['label' => 'Amount', 'value' => '₱'.number_format((float) ($record->amount ?? 0), 2)],
            ],
        );
        $hero['referenceLabel'] = 'Reference';

        return $hero;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forTransfer(Transfer $record): array
    {
        $record->loadMissing(['item', 'fromOffice', 'toOffice']);

        $hero = OwwaRecordHeroData::make(
            reference: $record->reference_code ?? '—',
            statusLabel: 'Transferred',
            statusClass: 'owwa-pc-status-badge--complete',
            meta: [
                ['label' => 'Item', 'value' => $record->item?->name ?? '—'],
                ['label' => 'Date', 'value' => $record->transfer_date?->format('M j, Y') ?? '—'],
                ['label' => 'From → To', 'value' => ($record->fromOffice?->name ?? '—').' → '.($record->toOffice?->name ?? '—')],
            ],
            kpis: [
                ['label' => 'Quantity', 'value' => (string) $record->quantity],
            ],
        );
        $hero['referenceLabel'] = 'Reference';

        return $hero;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forDisposal(Disposal $record): array
    {
        $record->loadMissing(['item', 'office']);

        $hero = OwwaRecordHeroData::make(
            reference: $record->reference_code ?? '—',
            statusLabel: match ($record->disposal_type) {
                'waste_sale' => 'Waste / Sale',
                'unserviceable' => 'Unserviceable',
                'lost_stolen_damaged' => 'Lost / Damaged',
                default => 'Disposed',
            },
            statusClass: 'owwa-pc-status-badge--incomplete',
            meta: [
                ['label' => 'Item', 'value' => $record->item?->name ?? '—'],
                ['label' => 'Date', 'value' => $record->disposal_date?->format('M j, Y') ?? '—'],
                ['label' => 'Office', 'value' => $record->office?->name ?? '—'],
            ],
            kpis: [
                ['label' => 'Quantity', 'value' => (string) $record->quantity],
            ],
        );
        $hero['referenceLabel'] = 'Reference';

        return $hero;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forItem(Item $record): array
    {
        $record->loadMissing(['category']);

        $hero = OwwaRecordHeroData::make(
            reference: $record->name ?? '—',
            statusLabel: $record->archived_at ? 'Archived' : 'Active',
            statusClass: $record->archived_at ? 'owwa-pc-status-badge--incomplete' : 'owwa-pc-status-badge--complete',
            meta: [
                ['label' => 'Stock no.', 'value' => $record->item_code ?? '—'],
                ['label' => 'Category', 'value' => $record->category?->name ?? '—'],
                ['label' => 'Unit', 'value' => $record->unit ?? '—'],
            ],
            kpis: [
                ['label' => 'Reorder at', 'value' => (string) ($record->reorder_level ?? '—')],
            ],
        );
        $hero['referenceLabel'] = 'Item';

        return $hero;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forDistribution(Distribution $record): array
    {
        $record->loadMissing(['item', 'distributedTo', 'requisition']);

        $hero = OwwaRecordHeroData::make(
            reference: $record->requisition?->reference_code ?? ('Distribution #'.$record->id),
            statusLabel: 'Distributed',
            statusClass: 'owwa-pc-status-badge--complete',
            meta: [
                ['label' => 'Item', 'value' => $record->item?->name ?? '—'],
                ['label' => 'Date', 'value' => $record->distribution_date?->format('M j, Y') ?? '—'],
                ['label' => 'Distributed to', 'value' => $record->distributedTo?->name ?? '—'],
            ],
            kpis: [
                ['label' => 'Quantity', 'value' => (string) $record->quantity],
            ],
        );
        $hero['referenceLabel'] = 'Reference';

        return $hero;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forUser(User $record): array
    {
        $record->loadMissing(['office', 'department']);

        $hero = OwwaRecordHeroData::make(
            reference: $record->name ?? '—',
            statusLabel: match ($record->role) {
                User::ROLE_SYSTEM_ADMIN => 'System Admin',
                User::ROLE_SUPPLY_CUSTODIAN => 'Supply Custodian',
                User::ROLE_UNIT_CONSOLIDATOR => 'Unit Consolidator',
                User::ROLE_EMPLOYEE => 'Employee',
                default => ucfirst((string) $record->role),
            },
            statusClass: 'owwa-pc-status-badge--progress',
            meta: [
                ['label' => 'Email', 'value' => $record->email ?? '—'],
                ['label' => 'Office', 'value' => $record->office?->name ?? '—'],
                ['label' => 'Department', 'value' => $record->department?->name ?? '—'],
            ],
        );
        $hero['referenceLabel'] = 'User';

        return $hero;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forAdminRecord(object $record, string $referenceLabel = 'Name'): array
    {
        $name = $record->name ?? $record->reference_code ?? ('#'.$record->getKey());
        $archived = property_exists($record, 'archived_at') && filled($record->archived_at);

        $hero = OwwaRecordHeroData::make(
            reference: (string) $name,
            statusLabel: $archived ? 'Archived' : 'Active',
            statusClass: $archived ? 'owwa-pc-status-badge--incomplete' : 'owwa-pc-status-badge--complete',
            meta: [],
        );
        $hero['referenceLabel'] = $referenceLabel;

        return $hero;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forUserLog(UserLog $record): array
    {
        $record->loadMissing(['user']);

        $statusLabel = $record->isOpen()
            ? 'Active session'
            : UserLog::logoutReasonLabel($record->logout_reason);

        $hero = OwwaRecordHeroData::make(
            reference: $record->user?->name ?? 'Unknown user',
            statusLabel: $statusLabel,
            statusClass: $record->isOpen() ? 'owwa-pc-status-badge--progress' : 'owwa-pc-status-badge--completed',
            meta: [
                ['label' => 'Logged in', 'value' => $record->logged_in_at?->format('M j, Y g:i A') ?? '—'],
                ['label' => 'Logged out', 'value' => $record->isOpen()
                    ? 'Still active'
                    : ($record->logged_out_at?->format('M j, Y g:i A') ?? '—')],
                ['label' => 'IP address', 'value' => $record->ip_address ?? '—'],
            ],
        );
        $hero['referenceLabel'] = 'User';

        return $hero;
    }
}
