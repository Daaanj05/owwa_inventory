<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserLog;
use App\Services\UserSessionAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSessionAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_touch_activity_skips_write_within_one_minute(): void
    {
        $user = User::factory()->create();
        $original = now()->subSeconds(30)->startOfSecond();

        $log = UserLog::query()->create([
            'user_id' => $user->id,
            'logged_in_at' => now()->subHour(),
            'last_activity_at' => $original,
            'session_id' => 'test-session',
        ]);

        app(UserSessionAuditService::class)->touchActivity((int) $log->id);

        $this->assertSame(
            $original->toDateTimeString(),
            $log->fresh()->last_activity_at?->toDateTimeString(),
        );
    }

    public function test_touch_activity_updates_when_older_than_one_minute(): void
    {
        $user = User::factory()->create();
        $stale = now()->subMinutes(2)->startOfSecond();

        $log = UserLog::query()->create([
            'user_id' => $user->id,
            'logged_in_at' => now()->subHour(),
            'last_activity_at' => $stale,
            'session_id' => 'test-session',
        ]);

        app(UserSessionAuditService::class)->touchActivity((int) $log->id);

        $this->assertNotSame(
            $stale->toDateTimeString(),
            $log->fresh()->last_activity_at?->toDateTimeString(),
        );
    }
}
