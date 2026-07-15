<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use App\Support\CustodianOfficeScope;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    public const ROLE_SYSTEM_ADMIN = 'system_admin';

    public const ROLE_SUPPLY_CUSTODIAN = 'supply_custodian';

    public const ROLE_UNIT_CONSOLIDATOR = 'unit_consolidator';

    public const ROLE_EMPLOYEE = 'employee';

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, LogsUserActivity, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'password',
        'must_change_password',
        'role',
        'office_id',
        'department_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            $first = trim((string) ($user->first_name ?? ''));
            $middle = trim((string) ($user->middle_name ?? ''));
            $last = trim((string) ($user->last_name ?? ''));

            if ($first === '' && $middle === '' && $last === '') {
                return;
            }

            $user->name = trim(implode(' ', array_values(array_filter([$first, $middle, $last], fn (string $v): bool => $v !== ''))));
        });
    }

    public function office()
    {
        return $this->belongsTo(Office::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{office_id: int, department_id: int}>
     */
    public static function normalizeAssignmentRows(array $rows): array
    {
        $normalized = [];
        $seen = [];

        foreach ($rows as $row) {
            $officeId = (int) ($row['office_id'] ?? 0);
            $departmentId = (int) ($row['department_id'] ?? 0);

            if ($officeId <= 0 || $departmentId <= 0) {
                continue;
            }

            $key = "{$officeId}:{$departmentId}";

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = [
                'office_id' => $officeId,
                'department_id' => $departmentId,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{office_id?: mixed, department_id?: mixed, departments?: array<int, array{department_id?: mixed}>}>  $groups
     * @return array<int, array{office_id: int, department_id: int}>
     */
    public static function flattenOfficeAssignmentGroups(array $groups): array
    {
        $rows = [];

        foreach ($groups as $group) {
            if (array_key_exists('department_id', $group)) {
                $officeId = (int) ($group['office_id'] ?? 0);
                $departmentId = (int) ($group['department_id'] ?? 0);

                if ($officeId <= 0 || $departmentId <= 0) {
                    continue;
                }

                $rows[] = [
                    'office_id' => $officeId,
                    'department_id' => $departmentId,
                ];

                continue;
            }

            $officeId = (int) ($group['office_id'] ?? 0);
            $departments = is_array($group['departments'] ?? null) ? $group['departments'] : [];

            foreach ($departments as $departmentRow) {
                $departmentId = (int) ($departmentRow['department_id'] ?? 0);

                if ($officeId <= 0 || $departmentId <= 0) {
                    continue;
                }

                $rows[] = [
                    'office_id' => $officeId,
                    'department_id' => $departmentId,
                ];
            }
        }

        return self::normalizeAssignmentRows($rows);
    }

    /**
     * @param  array<int, array{office_id: int, department_id: int}>  $rows
     * @return array<int, array{office_id: int, departments: array<int, array{department_id: int}>}>
     */
    public static function groupOfficeAssignmentsForForm(array $rows): array
    {
        $normalized = self::normalizeAssignmentRows($rows);
        $groups = [];

        foreach ($normalized as $row) {
            $officeId = $row['office_id'];
            $departmentId = $row['department_id'];

            if (! isset($groups[$officeId])) {
                $groups[$officeId] = [
                    'office_id' => $officeId,
                    'departments' => [],
                ];
            }

            $groups[$officeId]['departments'][] = [
                'department_id' => $departmentId,
            ];
        }

        return array_values($groups);
    }

    /**
     * @return HasMany<UserOfficeAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserOfficeAssignment::class);
    }

    /**
     * @param  array<int, array{office_id: int, department_id: int}>  $rows
     */
    public function syncOfficeAssignments(array $rows): void
    {
        $this->assignments()->delete();

        foreach ($rows as $row) {
            $officeId = (int) ($row['office_id'] ?? 0);
            $departmentId = (int) ($row['department_id'] ?? 0);

            if ($officeId <= 0 || $departmentId <= 0) {
                continue;
            }

            $this->assignments()->create([
                'office_id' => $officeId,
                'department_id' => $departmentId,
            ]);
        }

        $first = $this->assignments()->orderBy('id')->first();

        if ($first !== null) {
            $this->forceFill([
                'office_id' => $first->office_id,
                'department_id' => $first->department_id,
            ])->saveQuietly();
        }
    }

    public function coversOfficeDepartment(int $officeId, int $departmentId): bool
    {
        if ($this->isUnitConsolidator()) {
            return $this->assignments()
                ->where('office_id', $officeId)
                ->where('department_id', $departmentId)
                ->exists();
        }

        return (int) ($this->office_id ?? 0) === $officeId
            && (int) ($this->department_id ?? 0) === $departmentId;
    }

    /**
     * @return array<int, int>
     */
    public function assignedOfficeIds(): array
    {
        if ($this->isUnitConsolidator()) {
            return $this->assignments()
                ->pluck('office_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return $this->office_id ? [(int) $this->office_id] : [];
    }

    /**
     * @return array<int, int>
     */
    public function assignedDepartmentIds(): array
    {
        if ($this->isUnitConsolidator()) {
            return $this->assignments()
                ->pluck('department_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return $this->department_id ? [(int) $this->department_id] : [];
    }

    /**
     * @return array<int, int>
     */
    public function assignedDepartmentIdsForOffice(int $officeId): array
    {
        if (! $this->isUnitConsolidator()) {
            return (int) ($this->office_id ?? 0) === $officeId && $this->department_id
                ? [(int) $this->department_id]
                : [];
        }

        return $this->assignments()
            ->where('office_id', $officeId)
            ->pluck('department_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function hasSingleOfficeAssignment(): bool
    {
        return count($this->assignedOfficeIds()) === 1;
    }

    public function hasSingleDepartmentAssignmentForOffice(int $officeId): bool
    {
        return count($this->assignedDepartmentIdsForOffice($officeId)) === 1;
    }

    public function hasOfficeAssignment(int $officeId): bool
    {
        if ($this->isUnitConsolidator()) {
            return $this->assignments()->where('office_id', $officeId)->exists();
        }

        return (int) ($this->office_id ?? 0) === $officeId;
    }

    public function applyUnitConsolidatorRequisitionScope(Builder $query): Builder
    {
        if (! $this->isUnitConsolidator()) {
            return $query;
        }

        $assignments = $this->relationLoaded('assignments')
            ? $this->assignments
            : $this->assignments()->get();

        if ($assignments->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scoped) use ($assignments): void {
            foreach ($assignments as $assignment) {
                $scoped->orWhere(function (Builder $pair) use ($assignment): void {
                    $pair->where('office_id', $assignment->office_id)
                        ->where('department_id', $assignment->department_id);
                });
            }
        });
    }

    /**
     * @return HasOne<PasswordResetRequest, $this>
     */
    public function pendingPasswordResetRequest(): HasOne
    {
        return $this->hasOne(PasswordResetRequest::class)
            ->where('status', PasswordResetRequest::STATUS_PENDING)
            ->latestOfMany('requested_at');
    }

    /**
     * @return HasMany<PasswordResetRequest, $this>
     */
    public function passwordResetRequests(): HasMany
    {
        return $this->hasMany(PasswordResetRequest::class);
    }

    public function isSupplyCustodian(): bool
    {
        return $this->role === self::ROLE_SUPPLY_CUSTODIAN;
    }

    public function isUnitConsolidator(): bool
    {
        return $this->role === self::ROLE_UNIT_CONSOLIDATOR;
    }

    public function isEmployee(): bool
    {
        return $this->role === self::ROLE_EMPLOYEE;
    }

    public function isSystemAdmin(): bool
    {
        return $this->role === self::ROLE_SYSTEM_ADMIN;
    }

    public function canOverrideGeneratedCodes(): bool
    {
        return $this->isSystemAdmin();
    }

    public function mustChangePassword(): bool
    {
        return (bool) $this->must_change_password;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'system-admin' => $this->isSystemAdmin(),
            'admin' => in_array($this->role, [
                self::ROLE_SUPPLY_CUSTODIAN,
                self::ROLE_UNIT_CONSOLIDATOR,
                self::ROLE_EMPLOYEE,
            ], true),
            default => false,
        };
    }

    public static function panelLoginUrlFor(self $user): string
    {
        if ($user->isSystemAdmin()) {
            return url('/system-admin/login');
        }

        return url('/admin/login');
    }

    public static function guestEmailVerificationUrlFor(self $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify.guest',
            now()->addMinutes(config('auth.verification.expire', 60)),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ],
        );
    }

    /**
     * Office and department IDs to restrict consumption/analytics to for this user.
     * Supply Custodian sees all; Unit Consolidator and Employee see only their office (and department if set).
     *
     * @return array{office_ids: array<int>, department_ids: array<int>}
     */
    public function getConsumptionScope(): array
    {
        if ($this->isSupplyCustodian() || $this->isSystemAdmin()) {
            return ['office_ids' => [], 'department_ids' => []];
        }

        if ($this->isUnitConsolidator()) {
            return [
                'office_ids' => $this->assignedOfficeIds(),
                'department_ids' => $this->assignedDepartmentIds(),
            ];
        }

        $officeIds = $this->office_id ? [(int) $this->office_id] : [];
        $departmentIds = $this->department_id ? [(int) $this->department_id] : [];

        return ['office_ids' => $officeIds, 'department_ids' => $departmentIds];
    }

    public function inventoryOfficeId(): ?int
    {
        return CustodianOfficeScope::inventoryOfficeId($this);
    }

    public function hasFixedInventoryOffice(): bool
    {
        return CustodianOfficeScope::hasFixedInventoryOffice($this);
    }
}
