<?php

namespace App\Console\Commands;

use App\Models\AiProcurementRun;
use App\Models\Item;
use App\Models\Office;
use App\Models\RagEmbedding;
use App\Services\OllamaClient;
use Illuminate\Console\Command;

class ReindexRagEmbeddings extends Command
{
    protected $signature = 'rag:reindex {--source= : Limit to a source (policy|item|consumption|approved_run)} {--limit=500 : Limit number of records per source}';

    protected $description = 'Rebuild RAG embeddings for procurement analytics (MySQL JSON vectors).';

    public function handle(OllamaClient $ollama): int
    {
        $source = $this->option('source');
        $limit = (int) ($this->option('limit') ?? 500);

        if (! $ollama->isAvailable()) {
            $this->error('Ollama is not available. Start Ollama and ensure the embed model is installed.');

            return self::FAILURE;
        }

        $sources = $source ? [$source] : ['policy', 'item', 'consumption', 'approved_run'];

        foreach ($sources as $s) {
            match ($s) {
                'policy' => $this->indexPolicy($ollama),
                'item' => $this->indexItems($ollama, $limit),
                'consumption' => $this->indexConsumptionSummaries($ollama, $limit),
                'approved_run' => $this->indexApprovedRuns($ollama, $limit),
                default => $this->warn("Unknown source: {$s}"),
            };
        }

        $this->info('RAG reindex completed.');

        return self::SUCCESS;
    }

    protected function indexPolicy(OllamaClient $ollama): void
    {
        $content = <<<'TXT'
OWWA Region IV-A procurement decision support policy (system rules):
- Forecast monthly usage using moving average + trend slope on monthly issuance history.
- Items are at risk if months_of_cover < 3 OR current_stock <= reorder_level.
- Priority: High if months_of_cover < 1 or stock is critically below reorder; Medium if 1–3 or at/below reorder; omit Low.
- Suggested reorder quantity: reorder to reach target 3 months of forecasted usage (ceil(3 * forecast_monthly - current_stock), clamp at >= 0).
TXT;

        $embedding = $ollama->embed($content);
        if (! $embedding) {
            $this->warn('Failed to embed policy chunk.');

            return;
        }

        RagEmbedding::updateOrCreate(
            ['source' => 'policy', 'content' => $content],
            ['metadata' => [], 'embedding' => $embedding]
        );

        $this->info('Indexed policy chunk.');
    }

    protected function indexItems(OllamaClient $ollama, int $limit): void
    {
        $items = Item::query()
            ->whereNull('archived_at')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($items as $item) {
            $content = "Item: {$item->name}\nItem code: {$item->item_code}\nUnit: {$item->unit}\nReorder level: {$item->reorder_level}\nDescription: {$item->description}";
            $embedding = $ollama->embed($content);
            if (! $embedding) {
                continue;
            }

            RagEmbedding::updateOrCreate(
                ['source' => 'item', 'content' => $content],
                [
                    'metadata' => [
                        'category_id' => $item->item_category_id,
                        'item_id' => $item->id,
                    ],
                    'embedding' => $embedding,
                ]
            );

            $count++;
        }

        $this->info("Indexed {$count} item chunk(s).");
    }

    protected function indexConsumptionSummaries(OllamaClient $ollama, int $limit): void
    {
        $offices = Office::query()
            ->whereNull('archived_at')
            ->limit(50)
            ->get(['id', 'name']);

        $items = Item::query()
            ->whereNull('archived_at')
            ->limit(200)
            ->get(['id', 'name', 'item_category_id']);

        $count = 0;
        foreach ($items as $item) {
            foreach ($offices as $office) {
                if ($count >= $limit) {
                    break 2;
                }

                $content = "Consumption summary for {$item->name} at {$office->name}. Use issuance history to estimate demand and stockout risk.";
                $embedding = $ollama->embed($content);
                if (! $embedding) {
                    continue;
                }

                RagEmbedding::updateOrCreate(
                    ['source' => 'consumption', 'content' => $content],
                    [
                        'metadata' => [
                            'category_id' => $item->item_category_id,
                            'item_id' => $item->id,
                            'office_id' => $office->id,
                        ],
                        'embedding' => $embedding,
                    ]
                );

                $count++;
            }
        }

        $this->info("Indexed {$count} consumption chunk(s).");
    }

    protected function indexApprovedRuns(OllamaClient $ollama, int $limit): void
    {
        $runs = AiProcurementRun::query()
            ->where('status', 'approved')
            ->orderByDesc('ran_at')
            ->limit($limit)
            ->get(['id', 'summary', 'raw_response', 'period_from', 'period_to']);

        $count = 0;
        foreach ($runs as $run) {
            $content = trim((string) ($run->summary ?: ''))."\n\n".trim((string) $run->raw_response);
            $content = mb_substr($content, 0, 4000);

            $embedding = $ollama->embed($content);
            if (! $embedding) {
                continue;
            }

            RagEmbedding::updateOrCreate(
                ['source' => 'approved_run', 'content' => $content],
                [
                    'metadata' => [
                        'run_id' => $run->id,
                        'period_from' => $run->period_from?->toDateString(),
                        'period_to' => $run->period_to?->toDateString(),
                    ],
                    'embedding' => $embedding,
                ]
            );
            $count++;
        }

        $this->info("Indexed {$count} approved-run chunk(s).");
    }
}
