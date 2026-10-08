<?php

namespace Tests\Feature;

use App\Models\SystemHealthSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaptureSystemHealthSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_snapshot_command_persists_a_row(): void
    {
        $this->artisan('health:snapshot')
            ->assertSuccessful()
            ->expectsOutputToContain('Captured health snapshot');

        $this->assertSame(1, SystemHealthSnapshot::query()->count());
    }
}
