<?php

namespace Tests\Feature;

use App\Livewire\AiProcurementBusyChip;
use App\Models\AiProcurementRun;
use App\Models\Office;
use App\Models\User;
use App\Services\AiProcurementRecommendationService;
use App\Support\AiProcurementSummaryRestore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiProcurementBusyChipTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_chip_shows_for_processing_run_when_not_on_analytics(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $run = AiProcurementRun::query()->create([
            'ran_at' => now(),
            'period_from' => now()->subMonth()->toDateString(),
            'period_to' => now()->toDateString(),
            'status' => 'processing',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(AiProcurementBusyChip::class)
            ->assertSet('processingRunId', $run->id)
            ->assertSee('Generating recommendation');
    }

    public function test_chip_notifies_when_processing_run_completes(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $run = AiProcurementRun::query()->create([
            'ran_at' => now(),
            'period_from' => now()->subMonth()->toDateString(),
            'period_to' => now()->toDateString(),
            'status' => 'processing',
            'created_by' => $user->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test(AiProcurementBusyChip::class)
            ->assertSet('processingRunId', $run->id);

        $run->update(['status' => 'draft', 'summary' => 'Ready']);

        $component
            ->call('refreshProcessingRun')
            ->assertSet('processingRunId', null)
            ->assertNotified();
    }

    public function test_second_completion_watcher_does_not_send_duplicate_toast(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $run = AiProcurementRun::query()->create([
            'ran_at' => now(),
            'period_from' => now()->subMonth()->toDateString(),
            'period_to' => now()->toDateString(),
            'status' => 'processing',
            'created_by' => $user->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test(AiProcurementBusyChip::class)
            ->assertSet('processingRunId', $run->id);

        $run->update(['status' => 'draft', 'summary' => 'Ready']);

        $component
            ->call('refreshProcessingRun')
            ->assertNotified();

        $this->assertFalse(AiProcurementSummaryRestore::claimSessionToast($run->id));
    }

    public function test_service_sends_database_notification_when_run_fails(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $run = AiProcurementRun::query()->create([
            'ran_at' => now(),
            'period_from' => now()->subMonth()->toDateString(),
            'period_to' => now()->toDateString(),
            'status' => 'processing',
            'created_by' => $user->id,
        ]);

        app(AiProcurementRecommendationService::class)->markRunFailed($run->id, 'Worker unavailable');

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
        ]);

        $this->assertSame('failed', $run->fresh()->status);

        $notification = $user->notifications()->latest('id')->first();
        $this->assertNotNull($notification);
        $actions = data_get($notification->data, 'actions', []);
        $actionUrl = data_get($actions, '0.url')
            ?? data_get($actions, '0.data.url')
            ?? collect($actions)->pluck('url')->filter()->first();
        $this->assertNotNull($actionUrl);
        $this->assertStringContainsString('ai_run='.$run->id, (string) $actionUrl);
    }
}
