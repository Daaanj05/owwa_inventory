<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use App\Support\CustodianOfficeScope;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
