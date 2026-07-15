<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementSignatoryName extends Model
{
    public const ROLE_REQUESTED = 'requested';

    public const ROLE_APPROVED = 'approved';

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
