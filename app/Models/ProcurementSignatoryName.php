<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementSignatoryName extends Model
{
    public const ROLE_REQUESTED = 'requested';

    public const ROLE_REQUESTED_DESIGNATION = 'requested_designation';

    public const ROLE_APPROVED = 'approved';

    public const ROLE_APPROVED_DESIGNATION = 'approved_designation';

    public const ROLE_INSPECTION_OFFICER = 'inspection_officer';

    public const ROLE_CUSTODIAN = 'custodian';

    public const ROLE_TRANSFER_APPROVED = 'transfer_approved';

    public const ROLE_TRANSFER_APPROVED_DESIGNATION = 'transfer_approved_designation';

    public const ROLE_TRANSFER_RELEASED = 'transfer_released';

    public const ROLE_TRANSFER_RELEASED_DESIGNATION = 'transfer_released_designation';

    public const ROLE_TRANSFER_RECEIVED = 'transfer_received';

    public const ROLE_TRANSFER_RECEIVED_DESIGNATION = 'transfer_received_designation';

    public const ROLE_TRANSFER_FROM_ACCOUNTABLE = 'transfer_from_accountable';

    public const ROLE_TRANSFER_TO_ACCOUNTABLE = 'transfer_to_accountable';

    public const ROLE_PHYSICAL_COUNT_ACCOUNTABLE = 'physical_count_accountable';

    public const ROLE_PHYSICAL_COUNT_ACCOUNTABLE_DESIGNATION = 'physical_count_accountable_designation';

    public const ROLE_PHYSICAL_COUNT_CERTIFIED = 'physical_count_certified';

    public const ROLE_PHYSICAL_COUNT_APPROVED = 'physical_count_approved';

    public const ROLE_PHYSICAL_COUNT_VERIFIED = 'physical_count_verified';

    public const ROLE_DISPOSAL_WITNESS = 'disposal_witness';

    public const ROLE_DISPOSAL_AUTHORIZED_DESIGNATION = 'disposal_authorized_designation';

    public const ROLE_DISPOSAL_ACCOUNTABLE_DESIGNATION = 'disposal_accountable_designation';

    public const ROLE_DISPOSAL_ACCOUNTABLE_STATION = 'disposal_accountable_station';

    protected $fillable = [
        'name',
        'role',
    ];

    /**
     * @return list<string>
     */
    public static function suggestionsForRole(string $role): array
    {
        return static::query()
            ->where('role', $role)
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function optionsForRole(string $role): array
    {
        return collect(self::suggestionsForRole($role))
            ->mapWithKeys(fn (string $name): array => [$name => $name])
            ->all();
    }

    public static function remember(string $role, ?string $name): void
    {
        $normalized = trim((string) $name);
        if ($normalized === '') {
            return;
        }

        static::query()->firstOrCreate([
            'name' => $normalized,
            'role' => $role,
        ]);
    }
}
