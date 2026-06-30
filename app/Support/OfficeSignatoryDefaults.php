<?php

namespace App\Support;

use App\Models\Office;
use App\Models\User;

class OfficeSignatoryDefaults
{
    /**
     * @return array<string, string|null>
     */
    public static function forTransfer(?int $fromOfficeId, ?int $toOfficeId, ?User $recorder = null): array
    {
        $fromOffice = $fromOfficeId ? Office::query()->find($fromOfficeId) : null;
        $toOffice = $toOfficeId ? Office::query()->find($toOfficeId) : null;
        $recorder ??= auth()->user();

        return [
            'from_accountable_officer' => $fromOffice?->accountable_officer_name,
            'to_accountable_officer' => $toOffice?->accountable_officer_name,
            'released_by_printed_name' => $recorder instanceof User ? $recorder->name : $fromOffice?->supply_custodian_name,
            'released_by_designation' => $fromOffice?->supply_custodian_designation,
            'approved_by_printed_name' => $fromOffice?->authorized_officer_name,
            'approved_by_designation' => $fromOffice?->authorized_officer_designation,
            'received_by_printed_name' => $toOffice?->supply_custodian_name,
            'received_by_designation' => $toOffice?->supply_custodian_designation,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public static function forDisposal(?int $officeId, ?User $recorder = null): array
    {
        $office = $officeId ? Office::query()->find($officeId) : null;
        $recorder ??= auth()->user();

        return [
            'custodian_printed_name' => ($recorder instanceof User ? $recorder->name : null) ?? $office?->supply_custodian_name,
            'accountable_officer_designation' => $office?->accountable_officer_designation ?? $office?->supply_custodian_designation,
            'accountable_officer_station' => $office?->name,
            'approved_by_printed_name' => $office?->authorized_officer_name,
            'inspection_officer_printed_name' => $office?->inspection_officer_name,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public static function forIssuance(?int $officeId, ?User $custodian = null): array
    {
        $office = $officeId ? Office::query()->find($officeId) : null;
        $custodian ??= auth()->user();

        return [
            'custodian_printed_name' => ($custodian instanceof User ? $custodian->name : null) ?? $office?->supply_custodian_name,
            'custodian_designation' => $office?->supply_custodian_designation,
            'accounting_staff_printed_name' => $office?->authorized_officer_name,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public static function forPhysicalCountSession(?int $officeId): array
    {
        $office = $officeId ? Office::query()->find($officeId) : null;

        return [
            'fund_cluster' => $office?->fund_cluster,
            'accountable_officer_name' => $office?->accountable_officer_name,
            'accountable_officer_designation' => $office?->accountable_officer_designation,
        ];
    }

    /**
     * @param  array<string, string|null>  $defaults
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mergeNonBlank(array $defaults, array $data): array
    {
        foreach ($defaults as $key => $value) {
            if (blank($data[$key] ?? null) && filled($value)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }
}
