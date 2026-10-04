<?php

namespace Tests\Unit;

use App\Support\AiProcurementSummaryRestore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AiProcurementSummaryRestoreTest extends TestCase
{
    public function test_remember_skips_when_run_already_shown(): void
    {
        AiProcurementSummaryRestore::markShown(7, 18);

        AiProcurementSummaryRestore::remember(7, 18);

        $this->assertNull(Cache::get(AiProcurementSummaryRestore::cacheKey(7)));
        $this->assertTrue(AiProcurementSummaryRestore::hasBeenShown(7, 18));
    }
}
