<?php

namespace App\Services;

use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use App\Support\MailDelivery;
use App\Support\MailDeliveryResult;
use App\Support\OneTimeCode;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PasswordResetCodeService
{
    public function issueAndSendForEmail(string $email): MailDeliveryResult
    {
        $this->pruneExpiredRequests();

        $normalizedEmail = mb_strtolower(trim($email));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();

        if ($user === null) {
            return new MailDeliveryResult(false, false);
        }

        $adminPanel = Filament::getPanel('admin');

        if (
            $user instanceof FilamentUser
            && ! $user->canAccessPanel($adminPanel)
        ) {
            return new MailDeliveryResult(false, false);
        }

        $request = PasswordResetRequest::query()->create([
            'user_id' => $user->id,
            'status' => PasswordResetRequest::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        return $this->issueAndSend($request);
    }

    public function issueAndSend(PasswordResetRequest $request, ?User $actor = null): MailDeliveryResult
    {
        $user = $request->user;

        if ($user === null) {
            return new MailDeliveryResult(false, false);
        }

        $code = OneTimeCode::generate();
        $expiresAt = now()->addMinutes((int) config('auth.passwords.users.expire', 60));

        RateLimiter::clear($this->codeThrottleKey($user->email));

        $request->forceFill([
            'reset_code_hash' => OneTimeCode::hash($code),
            'reset_code_expires_at' => $expiresAt,
            'status' => PasswordResetRequest::STATUS_SENT,
            'handled_by' => $actor?->id,
            'handled_at' => now(),
        ])->save();

        $result = MailDelivery::notify($user, new PasswordResetCodeNotification(
            code: $code,
            panelLoginUrl: User::panelLoginUrlFor($user),
            expiresInMinutes: (int) config('auth.passwords.users.expire', 60),
        ));

        if ($result->success) {
            app(UserActivityLogger::class)->record(
                $actor ?? $user,
                'password_reset_sent',
                'Password reset code sent to '.$user->email,
                $request,
            );
        }

        return $result;
    }

    public function resetWithCode(string $email, string $code, string $password): bool
    {
        $normalizedEmail = mb_strtolower(trim($email));
        $normalizedCode = trim($code);

        return DB::transaction(function () use ($normalizedEmail, $normalizedCode, $password): bool {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                return false;
            }

            $request = PasswordResetRequest::query()
                ->where('user_id', $user->id)
                ->where('status', PasswordResetRequest::STATUS_SENT)
                ->whereNotNull('reset_code_hash')
                ->latest('handled_at')
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                return false;
            }

            if (
                $request->reset_code_hash === null
                || $request->reset_code_expires_at === null
                || $request->reset_code_expires_at->isPast()
            ) {
                return false;
            }

            if (! OneTimeCode::matches($normalizedCode, $request->reset_code_hash)) {
                $throttleKey = $this->codeThrottleKey($normalizedEmail);
                RateLimiter::hit($throttleKey, $this->codeDecaySeconds());

                if (RateLimiter::tooManyAttempts($throttleKey, $this->codeMaxAttempts())) {
                    $request->forceFill([
                        'reset_code_hash' => null,
                        'reset_code_expires_at' => null,
                    ])->save();
                }

                return false;
            }

            $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
                'remember_token' => Str::random(60),
            ])->save();

            $request->forceFill([
                'reset_code_hash' => null,
                'reset_code_expires_at' => null,
                'status' => PasswordResetRequest::STATUS_USED,
            ])->save();

            RateLimiter::clear($this->codeThrottleKey($normalizedEmail));

            return true;
        });
    }

    public function pruneExpiredRequests(): int
    {
        $days = (int) config('inventory.password_reset_request_retention_days', 30);
        $cutoff = now()->subDays($days);

        return PasswordResetRequest::query()
            ->where('created_at', '<', $cutoff)
            ->delete();
    }

    protected function codeThrottleKey(string $email): string
    {
        return 'password-reset-code:'.sha1(mb_strtolower(trim($email)));
    }

    protected function codeMaxAttempts(): int
    {
        return max(1, (int) config('inventory.one_time_code_max_attempts', 5));
    }

    protected function codeDecaySeconds(): int
    {
        return max(1, (int) config('auth.passwords.users.expire', 60) * 60);
    }
}
