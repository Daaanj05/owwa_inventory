<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    public const ROLE_SYSTEM_ADMIN = 'system_admin';
    public const ROLE_SUPPLY_CUSTODIAN = 'supply_custodian';
    public const ROLE_AUTHORIZED_PERSONNEL = 'authorized_personnel';
    public const ROLE_EMPLOYEE = 'employee';

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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
        ];
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

    public function isAuthorizedPersonnel(): bool
    {
        return $this->role === self::ROLE_AUTHORIZED_PERSONNEL;
    }

    public function isEmployee(): bool
    {
        return $this->role === self::ROLE_EMPLOYEE;
    }

    public function isSystemAdmin(): bool
    {
        return $this->role === self::ROLE_SYSTEM_ADMIN;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'system-admin' => $this->isSystemAdmin(),
            'admin' => in_array($this->role, [
                self::ROLE_SUPPLY_CUSTODIAN,
                self::ROLE_AUTHORIZED_PERSONNEL,
                self::ROLE_EMPLOYEE,
            ], true),
            default => false,
        };
    }

    /**
     * Office and department IDs to restrict consumption/analytics to for this user.
     * Supply Custodian sees all; Unit Head and Employee see only their office (and department if set).
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
}
