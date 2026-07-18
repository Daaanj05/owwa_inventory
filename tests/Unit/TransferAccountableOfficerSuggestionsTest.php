<?php

namespace Tests\Unit;

use App\Filament\Resources\Transfers\Schemas\TransferForm;
use App\Models\Office;
use App\Models\ProcurementSignatoryName;
use App\Models\User;
use App\Support\OfficeSignatoryDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferAccountableOfficerSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestions_prefer_unit_consolidators_over_all_office_users(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'name' => 'UC Alice',
        ]);
        User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'name' => 'Employee Bob',
        ]);

        $suggestions = TransferForm::accountableOfficerSuggestions(
            $office->id,
            ProcurementSignatoryName::ROLE_TRANSFER_FROM_ACCOUNTABLE,
        );

        $this->assertContains($uc->name, $suggestions);
        $this->assertNotContains('Employee Bob', $suggestions);
    }

    public function test_default_accountable_officer_uses_single_uc(): void
    {
        $office = Office::factory()->create([
            'accountable_officer_name' => 'Master Name',
        ]);
        User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'name' => 'Only UC',
        ]);

        $this->assertSame('Only UC', TransferForm::defaultAccountableOfficerName($office->id));

        $defaults = OfficeSignatoryDefaults::forTransfer($office->id, $office->id);
        $this->assertSame('Only UC', $defaults['from_accountable_officer']);
        $this->assertSame('Only UC', $defaults['to_accountable_officer']);
    }
}
