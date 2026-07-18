<?php

namespace Tests\Unit;

use App\Models\ProcurementSignatoryName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferSignatoryRememberTest extends TestCase
{
    use RefreshDatabase;

    public function test_remember_persists_unique_names_per_role(): void
    {
        ProcurementSignatoryName::remember(ProcurementSignatoryName::ROLE_TRANSFER_APPROVED, 'Juan Dela Cruz');
        ProcurementSignatoryName::remember(ProcurementSignatoryName::ROLE_TRANSFER_APPROVED, 'Juan Dela Cruz');
        ProcurementSignatoryName::remember(ProcurementSignatoryName::ROLE_TRANSFER_APPROVED, 'Maria Santos');
        ProcurementSignatoryName::remember(ProcurementSignatoryName::ROLE_TRANSFER_APPROVED, '  ');

        $suggestions = ProcurementSignatoryName::suggestionsForRole(ProcurementSignatoryName::ROLE_TRANSFER_APPROVED);

        $this->assertSame(['Juan Dela Cruz', 'Maria Santos'], $suggestions);
        $this->assertSame(2, ProcurementSignatoryName::query()
            ->where('role', ProcurementSignatoryName::ROLE_TRANSFER_APPROVED)
            ->count());
    }
}
