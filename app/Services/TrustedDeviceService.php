<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class TrustedDeviceService
{
    public const string COOKIE = 'owwa_trusted_device';

    public function days(): int
    {
        return max(1, (int) config('inventory.trusted_device_days', 15));
    }

    public function isCurrentDeviceTrusted(User $user): bool
    {
        $token = $this->readCookieToken();

        if ($token === null) {
            return false;
        }

        $device = TrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('token_hash', $this->hash($token))
            ->where('expires_at', '>', now())
            ->first();

        if ($device === null) {
            $this->forgetCookie();

            return false;
        }

        $device->forceFill([
            'last_used_at' => now(),
        ])->save();

        return true;
    }

    public function trustCurrentDevice(User $user): TrustedDevice
    {
        $token = Str::random(64);
        $minutes = $this->days() * 24 * 60;

        $device = TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => $this->hash($token),
            'label' => $this->deviceLabel(),
            'ip_address' => request()->ip(),
            'expires_at' => now()->addMinutes($minutes),
            'last_used_at' => now(),
        ]);

        Cookie::queue(cookie(
            name: self::COOKIE,
            value: $token,
            minutes: $minutes,
            path: '/',
            secure: (bool) config('session.secure'),
            httpOnly: true,
            sameSite: config('session.same_site', 'lax'),
        ));

        $this->pruneExpiredForUser($user);

        return $device;
    }

    public function revoke(TrustedDevice $device, User $user): void
    {
        if ((int) $device->user_id !== (int) $user->id) {
            return;
        }

        $token = $this->readCookieToken();

        if ($token !== null && hash_equals($device->token_hash, $this->hash($token))) {
            $this->forgetCookie();
        }

        $device->delete();
    }

    public function revokeAllForUser(User $user): void
    {
        TrustedDevice::query()->where('user_id', $user->id)->delete();
        $this->forgetCookie();
    }

    public function pruneExpiredForUser(User $user): int
    {
        return TrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('expires_at', '<=', now())
            ->delete();
    }

    public function forgetCookie(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    protected function readCookieToken(): ?string
    {
        $token = request()->cookie(self::COOKIE);

        if (! is_string($token) || strlen($token) < 32) {
            return null;
        }

        return $token;
    }

    protected function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    protected function deviceLabel(): string
    {
        $agent = (string) request()->userAgent();

        if ($agent === '') {
            return 'Trusted browser';
        }

        return Str::limit($agent, 120);
    }
}
