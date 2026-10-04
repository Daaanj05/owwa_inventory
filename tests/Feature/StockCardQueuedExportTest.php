<?php

namespace Tests\Feature;

use App\Jobs\GenerateStockCardExportJob;
use App\Models\User;
use App\Notifications\StockCardExportReadyDatabaseNotification;
use App\Services\StockCardExportStatusService;
use App\Services\StockCardQueuedExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StockCardQueuedExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_notifies_user_with_signed_download_url_on_success(): void
    {
        Notification::fake();
        Storage::fake('local');

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        $path = StockCardQueuedExportService::DIRECTORY.'/'.$user->id.'/abc-SC-batch.xlsx';
        Storage::disk('local')->put($path, 'fake-xlsx');

        $this->mock(StockCardQueuedExportService::class, function ($mock) use ($path): void {
            $mock->shouldReceive('buildAndStore')->once()->andReturn([
                'disk' => 'local',
                'path' => $path,
                'filename' => 'SC-batch.xlsx',
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        });

        (new GenerateStockCardExportJob(
            userId: $user->id,
            categorySlug: 'consumables',
            format: 'xlsx',
            downloadSize: 'one',
            scope: 'selected',
            selectedKeys: ['1:1:0'],
        ))->handle(
            app(StockCardQueuedExportService::class),
            app(StockCardExportStatusService::class),
        );

        Notification::assertSentTo(
            $user,
            StockCardExportReadyDatabaseNotification::class,
            function (StockCardExportReadyDatabaseNotification $notification) use ($user): bool {
                return ! $notification->failed
                    && filled($notification->downloadUrl)
                    && $notification->previewUrl === null
                    && str_contains($notification->downloadUrl, 'exports/stock-cards/'.$user->id.'/');
            },
        );
    }

    public function test_job_sets_preview_url_for_pdf_exports(): void
    {
        Notification::fake();
        Storage::fake('local');

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        $path = StockCardQueuedExportService::DIRECTORY.'/'.$user->id.'/abc-SC-batch.pdf';
        Storage::disk('local')->put($path, '%PDF-fake');

        $this->mock(StockCardQueuedExportService::class, function ($mock) use ($path): void {
            $mock->shouldReceive('buildAndStore')->once()->andReturn([
                'disk' => 'local',
                'path' => $path,
                'filename' => 'SC-batch.pdf',
                'mime' => 'application/pdf',
            ]);
        });

        (new GenerateStockCardExportJob(
            userId: $user->id,
            categorySlug: 'consumables',
            format: 'pdf',
            downloadSize: 'one',
            scope: 'selected',
            selectedKeys: ['1:1:0'],
        ))->handle(
            app(StockCardQueuedExportService::class),
            app(StockCardExportStatusService::class),
        );

        Notification::assertSentTo(
            $user,
            StockCardExportReadyDatabaseNotification::class,
            function (StockCardExportReadyDatabaseNotification $notification): bool {
                return filled($notification->downloadUrl)
                    && filled($notification->previewUrl)
                    && str_contains((string) $notification->previewUrl, 'inline=1');
            },
        );

        $status = app(StockCardExportStatusService::class)->statusFor((int) $user->id);
        $this->assertSame('ready', $status['status'] ?? null);
        $this->assertNotEmpty($status['preview_url'] ?? null);
        $this->assertStringContainsString('inline=1', (string) $status['preview_url']);
    }

    public function test_job_skips_database_notification_when_flag_false(): void
    {
        Notification::fake();
        Storage::fake('local');

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        $path = StockCardQueuedExportService::DIRECTORY.'/'.$user->id.'/abc-SC-batch.xlsx';
        Storage::disk('local')->put($path, 'fake-xlsx');

        $this->mock(StockCardQueuedExportService::class, function ($mock) use ($path): void {
            $mock->shouldReceive('buildAndStore')->once()->andReturn([
                'disk' => 'local',
                'path' => $path,
                'filename' => 'SC-batch.xlsx',
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        });

        (new GenerateStockCardExportJob(
            userId: $user->id,
            categorySlug: 'consumables',
            format: 'xlsx',
            downloadSize: 'one',
            scope: 'selected',
            selectedKeys: ['1:1:0'],
            notifyDatabase: false,
        ))->handle(
            app(StockCardQueuedExportService::class),
            app(StockCardExportStatusService::class),
        );

        Notification::assertNotSentTo($user, StockCardExportReadyDatabaseNotification::class);

        $status = app(StockCardExportStatusService::class)->statusFor((int) $user->id);
        $this->assertSame('ready', $status['status'] ?? null);
    }

    public function test_job_failure_notifies_without_download_url(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        (new GenerateStockCardExportJob(
            userId: $user->id,
            categorySlug: 'consumables',
            format: 'pdf',
            downloadSize: 'batches',
            scope: 'selected',
            selectedKeys: ['1:1:0'],
        ))->failed(new \RuntimeException('boom'));

        Notification::assertSentTo(
            $user,
            StockCardExportReadyDatabaseNotification::class,
            function (StockCardExportReadyDatabaseNotification $notification): bool {
                return $notification->failed && $notification->downloadUrl === null;
            },
        );
    }

    public function test_signed_download_requires_owner(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $other = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $path = StockCardQueuedExportService::DIRECTORY.'/'.$owner->id.'/file.xlsx';
        Storage::disk('local')->put($path, 'data');

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'owwa.export.stock-cards.download',
            now()->addHour(),
            ['user' => $owner->id, 'file' => 'file.xlsx'],
        );

        $this->actingAs($other)->get($url)->assertForbidden();
        $this->actingAs($owner)->get($url)->assertOk();
    }
}
