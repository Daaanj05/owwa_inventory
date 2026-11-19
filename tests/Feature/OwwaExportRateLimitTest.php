<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwwaExportRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_heavy_exports_are_rate_limited_per_user(): void
    {
        /** @var User $user */
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email_verified_at' => now(),
        ]);

        $url = route('owwa.export.issuance', ['issuance' => 999999]);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->get($url)->assertNotFound();
        }

        $this->actingAs($user)->get($url)->assertStatus(429);
    }
}
