<?php

namespace App\Filament\Concerns;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;

trait InteractsWithProfileUser
{
    protected function loadProfileUserRelations(): void
    {
        $user = Filament::auth()->user();

        if ($user instanceof User) {
            $user->load([
                'office',
                'department',
                'assignments.office',
                'assignments.department',
                'pendingPasswordResetRequest',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function backfillNamePartsFromCombinedName(array $data): array
    {
        if (blank($data['first_name'] ?? null) && filled($data['name'] ?? null)) {
            $parts = preg_split('/\s+/', trim((string) $data['name'])) ?: [];
            $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));

            $data['first_name'] = $parts[0] ?? '';
            $data['last_name'] = count($parts) > 1 ? $parts[count($parts) - 1] : '';
            $data['middle_name'] = count($parts) > 2
                ? implode(' ', array_slice($parts, 1, -1))
                : ($data['middle_name'] ?? null);
        }

        return $data;
    }

    protected function profileUser(): User
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            throw new \LogicException('Authenticated user must be an instance of '.User::class);
        }

        return $user;
    }

    protected function showsSingleOfficeDepartment(): bool
    {
        $user = $this->profileUser();

        return ! $user->isUnitConsolidator() && ! $user->isSystemAdmin();
    }

    protected function formatAssignmentsList(): Htmlable|string
    {
        $user = $this->profileUser();

        $lines = $user->assignments
            ->map(fn ($assignment): string => trim(
                ($assignment->office?->name ?? '—').' — '.($assignment->department?->name ?? '—'),
            ))
            ->filter(fn (string $line): bool => $line !== '— —')
            ->values();

        if ($lines->isEmpty()) {
            return '—';
        }

        return $lines->implode("\n");
    }

    public static function roleLabel(User $user): string
    {
        return match ($user->role) {
            User::ROLE_SYSTEM_ADMIN => 'System Admin',
            User::ROLE_SUPPLY_CUSTODIAN => 'Supply Custodian',
            User::ROLE_UNIT_CONSOLIDATOR => 'Unit Consolidator',
            User::ROLE_EMPLOYEE => 'Employee',
            default => (string) $user->role,
        };
    }

    public function profileDisplayName(): string
    {
        $user = $this->profileUser();

        return trim(collect([
            $user->first_name,
            $user->middle_name,
            $user->last_name,
        ])->filter(fn (?string $part): bool => filled($part))->implode(' ')) ?: ($user->name ?? $user->email);
    }

    public function profileInitials(): string
    {
        $user = $this->profileUser();
        $first = strtoupper(substr(trim((string) ($user->first_name ?: $user->name)), 0, 1));
        $last = strtoupper(substr(trim((string) ($user->last_name ?: '')), 0, 1));

        if ($first === '' && $last === '') {
            return strtoupper(substr((string) $user->email, 0, 2));
        }

        return $first.($last !== '' ? $last : '');
    }

    public function roleBadgeClass(): string
    {
        return match ($this->profileUser()->role) {
            User::ROLE_SYSTEM_ADMIN => 'owwa-account-badge--danger',
            User::ROLE_SUPPLY_CUSTODIAN => 'owwa-account-badge--primary',
            User::ROLE_UNIT_CONSOLIDATOR => 'owwa-account-badge--info',
            default => 'owwa-account-badge--neutral',
        };
    }

    public function verificationBadge(): array
    {
        $verified = $this->profileUser()->hasVerifiedEmail();

        return [
            'label' => $verified ? 'Verified' : 'Pending',
            'class' => $verified ? 'owwa-account-badge--success' : 'owwa-account-badge--warning',
        ];
    }

    protected function accountBackAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('back')
            ->label('Back')
            ->icon(\Filament\Support\Icons\Heroicon::OutlinedArrowLeft)
            ->color('gray')
            ->url(Filament::getUrl());
    }
}
