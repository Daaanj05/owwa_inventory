<?php

namespace Tests\Feature;

use App\Filament\Resources\AiProcurementRunResource\Pages\ListAiProcurementRuns;
use App\Models\AiProcurementRun;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiProcurementRunArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_hides_run_without_changing_status_and_restore_brings_it_back(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);
        $this->actingAs($custodian);

        $pending = AiProcurementRun::query()->create([
            'ran_at' => now(),
            'summary' => 'Pending run',
            'status' => 'pending',
        ]);
        $approved = AiProcurementRun::query()->create([
            'ran_at' => now()->subMinute(),
            'summary' => 'Approved run',
            'status' => 'approved',
        ]);

        Livewire::test(ListAiProcurementRuns::class)
            ->assertSet('showingArchived', false)
            ->assertSeeHtml('owwa-setup-archive-view-toggle')
            ->assertSeeHtml('aria-label="Active"')
            ->assertSeeHtml('aria-label="Archived"')
            ->assertDontSee('Show archived runs')
            ->assertCanSeeTableRecords([$pending, $approved])
            ->callTableAction('archive', $pending)
            ->assertCanNotSeeTableRecords([$pending])
            ->assertCanSeeTableRecords([$approved]);

        $pending->refresh();
        $this->assertSame('pending', $pending->status);
        $this->assertTrue($pending->isArchived());

        Livewire::test(ListAiProcurementRuns::class)
            ->callTableAction('archive', $approved);

        $approved->refresh();
        $this->assertSame('approved', $approved->status);
        $this->assertTrue($approved->isArchived());

        Livewire::test(ListAiProcurementRuns::class)
            ->assertCanNotSeeTableRecords([$pending, $approved])
            ->set('showingArchived', true)
            ->assertCanSeeTableRecords([$pending, $approved])
            ->callTableAction('restore', $pending)
            ->assertCanNotSeeTableRecords([$pending]);

        $pending->refresh();
        $this->assertSame('pending', $pending->status);
        $this->assertFalse($pending->isArchived());

        Livewire::test(ListAiProcurementRuns::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$approved]);
    }
}
