<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementSignatoryName extends Model
{
    public const ROLE_REQUESTED = 'requested';

    public const ROLE_APPROVED = 'approved';

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
