<?php

namespace Tests\Unit;

use App\Support\AiProcurementSummaryRestore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AiProcurementSummaryRestoreTest extends TestCase
{
    public function test_remember_keeps_active_run_even_after_shown(): void
    {
        AiProcurementSummaryRestore::markShown(7, 18);

        AiProcurementSummaryRestore::remember(7, 18);

        $this->assertSame(18, AiProcurementSummaryRestore::active(7));
        $this->assertTrue(AiProcurementSummaryRestore::hasBeenShown(7, 18));
    }

    public function test_forget_clears_active_run(): void
    {
        AiProcurementSummaryRestore::remember(7, 22);
        AiProcurementSummaryRestore::forget(7);

        $this->assertNull(AiProcurementSummaryRestore::active(7));
        $this->assertNull(Cache::get(AiProcurementSummaryRestore::cacheKey(7)));
    }
}
