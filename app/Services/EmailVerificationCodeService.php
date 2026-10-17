<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AccountSetupInviteNotification;
use App\Notifications\EmailVerificationCodeNotification;
use App\Support\MailDelivery;
use App\Support\MailDeliveryResult;
use App\Support\OneTimeCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmailVerificationCodeService
{
    public function issue(User $user): string
    {
        $code = OneTimeCode::generate();
        $expiresAt = now()->addMinutes((int) config('auth.verification.expire', 60));

        $user->forceFill([
            'email_verification_code_hash' => OneTimeCode::hash($code),
            'email_verification_code_expires_at' => $expiresAt,
        ])->save();

        return $code;
    }

    public function sendSetupInvite(User $user): MailDeliveryResult
    {
        return MailDelivery::notify($user, new AccountSetupInviteNotification(
            setupUrl: User::panelSetupUrlFor($user),
            panelLoginUrl: User::panelLoginUrlFor($user),
        ));
    }

    public function send(User $user): MailDeliveryResult
    {
        return $this->sendCode($user);
    }

    public function sendCode(User $user): MailDeliveryResult
    {
        $code = $this->issue($user);

        return MailDelivery::notify($user, new EmailVerificationCodeNotification(
            code: $code,
            expiresInMinutes: (int) config('auth.verification.expire', 60),
        ));
    }

    public function requestSetupCodeForEmail(string $email): void
    {
        $normalizedEmail = mb_strtolower(trim($email));

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();

        if ($user === null || $user->hasVerifiedEmail()) {
            return;
        }

        $this->sendCode($user);
    }

    public function codeIsValid(string $email, string $code): bool
    {
        $user = $this->findUnverifiedUserForEmail($email);

        if ($user === null) {
            return false;
        }

        return $this->userCodeMatches($user, $code);
    }

    public function completeSetup(string $email, string $code, string $password): ?User
    {
        $normalizedEmail = mb_strtolower(trim($email));
        $normalizedCode = trim($code);

        return DB::transaction(function () use ($normalizedEmail, $normalizedCode, $password): ?User {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->lockForUpdate()
                ->first();

            if ($user === null || $user->hasVerifiedEmail()) {
                return null;
            }

            if (! $this->userCodeMatches($user, $normalizedCode)) {
                return null;
            }

            $user->forceFill([
                'password' => $password,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'email_verification_code_hash' => null,
                'email_verification_code_expires_at' => null,
                'remember_token' => Str::random(60),
            ])->save();

            return $user->fresh();
        });
    }

    public function clear(User $user): void
    {
        $user->forceFill([
            'email_verification_code_hash' => null,
            'email_verification_code_expires_at' => null,
        ])->save();
    }

    protected function findUnverifiedUserForEmail(string $email): ?User
    {
        $normalizedEmail = mb_strtolower(trim($email));

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();

        if ($user === null || $user->hasVerifiedEmail()) {
            return null;
        }

        return $user;
    }

    protected function userCodeMatches(User $user, string $code): bool
    {
        $normalizedCode = trim($code);

        if (
            $user->email_verification_code_expires_at === null
            || $user->email_verification_code_expires_at->isPast()
        ) {
            return false;
        }

        return OneTimeCode::matches($normalizedCode, $user->email_verification_code_hash);
    }
}
