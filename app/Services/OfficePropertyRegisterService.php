<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\User;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class OfficePropertyRegisterService
{
    /**
     * @return Builder<Issuance>
     */
    public function queryForUser(User $user): Builder
    {
        $query = Issuance::query()
            ->with(['item.category', 'office', 'department', 'issuedTo'])
            ->whereHas('item.category', function (Builder $categoryQuery): void {
                $categoryQuery->whereIn('name', $this->propertyCategoryNames());
            });

        if ($user->isUnitConsolidator()) {
            $query->where(function (Builder $scope) use ($user): void {
                $scope->where('issued_to', $user->id);

                if ($user->office_id) {
                    $scope->orWhere(function (Builder $officeScope) use ($user): void {
                        $officeScope->where('office_id', $user->office_id);

                        if ($user->department_id) {
                            $officeScope->where('department_id', $user->department_id);
                        }
                    });
                }
            });
        }

        return $query;
    }

    public function paginateForUser(User $user, string $search = '', string $sortBy = 'issuance_date', string $sortDir = 'desc', int $perPage = 10): LengthAwarePaginator
    {
        $query = $this->queryForUser($user);

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $scope) use ($like): void {
                $scope->where('property_number', 'like', $like)
                    ->orWhereHas('item', fn (Builder $itemQuery) => $itemQuery->where('name', 'like', $like))
                    ->orWhereHas('item.category', fn (Builder $categoryQuery) => $categoryQuery->where('name', 'like', $like));
            });
        }

        $column = match ($sortBy) {
            'property_number' => 'property_number',
            'item_name' => 'items.name',
            'category_name' => 'item_categories.name',
            'estimated_useful_life' => 'estimated_useful_life',
            'eul_expires_at' => 'eul_expires_at',
            default => 'issuance_date',
        };

        if (in_array($sortBy, ['item_name', 'category_name'], true)) {
            $query->join('items', 'items.id', '=', 'issuances.item_id');

            if ($sortBy === 'category_name') {
                $query->join('item_categories', 'item_categories.id', '=', 'items.item_category_id');
            }

            $query->select('issuances.*');
        }

        $query->orderBy($column, $sortDir === 'asc' ? 'asc' : 'desc');

        return $query->paginate($perPage)->withQueryString()->onEachSide(0);
    }

    public function countNearingExpiryForUser(User $user): int
    {
        return $this->queryForUser($user)
            ->whereNotNull('eul_expires_at')
            ->get()
            ->filter(function (Issuance $issuance): bool {
                $status = SemiExpendableUsefulLife::statusForIssuance($issuance);

                return in_array($status, [SemiExpendableUsefulLife::STATUS_NEARING, SemiExpendableUsefulLife::STATUS_EXPIRED], true);
            })
            ->count();
    }

    /**
     * @return list<string>
     */
    protected function propertyCategoryNames(): array
    {
        return \App\Models\ItemCategory::query()
            ->get()
            ->filter(fn (\App\Models\ItemCategory $category): bool => in_array($category->getTemplateSlug(), ['ppe', 'semi_expendable'], true))
            ->pluck('name')
            ->all();
    }
}
