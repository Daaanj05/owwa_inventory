<?php

namespace App\Support;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class CustodianOfficeScope
{
    public static function inventoryOfficeId(?User $user = null): ?int
    {
        $user ??= auth()->user();

        if (! $user instanceof User || ! $user->isSupplyCustodian() || ! $user->office_id) {
            return null;
        }

        return (int) $user->office_id;
    }

    public static function hasFixedInventoryOffice(?User $user = null): bool
    {
        return self::inventoryOfficeId($user) !== null;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public static function officeOptions(?User $user = null): array
    {
        return self::officeQuery(Office::query(), $user)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Office $office): array => ['id' => $office->id, 'name' => $office->name])
            ->all();
    }

    public static function officeQuery(Builder $query, ?User $user = null): Builder
    {
        $query = $query->active();

        $officeId = self::inventoryOfficeId($user);

        if ($officeId !== null) {
            $query->whereKey($officeId);
        }

        return $query;
    }

    public static function applyOfficeColumn(Builder $query, string $column = 'office_id', ?User $user = null): Builder
    {
        $officeId = self::inventoryOfficeId($user);

        if ($officeId !== null) {
            $query->where($column, $officeId);
        }

        return $query;
    }

    public static function applyTransferOfficeScope(Builder $query, ?User $user = null): Builder
    {
        $officeId = self::inventoryOfficeId($user);

        if ($officeId === null) {
            return $query;
        }

        return $query->where(function (Builder $scoped) use ($officeId): void {
            $scoped->where('from_office_id', $officeId)
                ->orWhere('to_office_id', $officeId);
        });
    }

    /**
     * @throws ValidationException
     */
    public static function assertOfficeAllowed(int $officeId, ?User $user = null, string $field = 'office_id'): void
    {
        $fixedOfficeId = self::inventoryOfficeId($user);

        if ($fixedOfficeId === null) {
            return;
        }

        if ($officeId !== $fixedOfficeId) {
            throw ValidationException::withMessages([
                $field => 'You can only record inventory for your assigned office.',
            ]);
        }
    }
}
