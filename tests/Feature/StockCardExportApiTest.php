<?php

namespace Tests\Feature;

use App\Jobs\GenerateStockCardExportJob;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\StockCardExportStatusService;
use App\Services\StockLevelExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StockCardExportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_queues_export_and_status_reports_queued(): void
    {
        Queue::fake();
        Cache::flush();

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $items = Item::factory()
            ->count(StockLevelExportService::BATCH_SIZE + 1)
            ->create(['item_category_id' => $category->id]);

        $keys = $items
            ->map(fn (Item $item): string => $item->id.':'.$office->id)
            ->values()
            ->all();

        $this->actingAs($custodian);
        $this->withSession([]);
        $csrf = (string) $this->app['session']->token();

        $this->withHeader('X-CSRF-TOKEN', $csrf)
            ->postJson(route('api.stock-card-exports.store'), [
                'format' => 'xlsx',
                'scope' => 'selected',
                'download_size' => 'batches',
                'category_id' => $category->id,
                'selected_keys' => $keys,
            ])
            ->assertAccepted()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('export_count', count($keys));

        Queue::assertPushed(GenerateStockCardExportJob::class, function (GenerateStockCardExportJob $job) use ($custodian, $keys): bool {
            return $job->userId === (int) $custodian->id
                && $job->scope === 'selected'
                && count($job->selectedKeys) === count($keys);
        });

        $this->getJson(route('api.stock-card-exports.status'))
            ->assertOk()
            ->assertJsonPath('status', 'queued');
    }

    public function test_status_idle_when_no_export(): void
    {
        Cache::flush();

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        $this->actingAs($custodian)
            ->getJson(route('api.stock-card-exports.status'))
            ->assertOk()
            ->assertJsonPath('status', 'idle');

        $this->assertNull(app(StockCardExportStatusService::class)->statusFor((int) $custodian->id));
    }
}
