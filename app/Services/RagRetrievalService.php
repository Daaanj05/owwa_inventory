<?php

namespace App\Services;

use App\Models\RagEmbedding;
use Illuminate\Support\Collection;

class RagRetrievalService
{
    public function __construct(
        protected OllamaClient $ollama,
    ) {}

    /**
     * Retrieve top-K relevant chunks for a query, using:
     * - metadata filtering in MySQL (candidate set)
     * - cosine similarity ranking in PHP (because MySQL has no vector index)
     *
     * @param  array{category_id?:int|null,fiscal_year_id?:int|null,office_ids?:array<int>}  $filters
     * @return Collection<int, array{source:string,content:string,metadata:array|null,score:float}>
     */
    public function retrieve(string $query, array $filters = [], int $topK = 20, int $candidateLimit = 400): Collection
    {
        $embedding = $this->ollama->embed($query);
        if (! $embedding) {
            return collect();
        }

        $candidates = RagEmbedding::query()
            ->when(array_key_exists('category_id', $filters) && $filters['category_id'] !== null, function ($q) use ($filters) {
                $q->where('metadata->category_id', (int) $filters['category_id']);
            })
            ->when(array_key_exists('fiscal_year_id', $filters) && $filters['fiscal_year_id'] !== null, function ($q) use ($filters) {
                $q->where('metadata->fiscal_year_id', (int) $filters['fiscal_year_id']);
            })
            ->when(isset($filters['office_ids']) && $filters['office_ids'] !== [], function ($q) use ($filters) {
                $q->whereIn('metadata->office_id', array_map('intval', $filters['office_ids']));
            })
            ->orderByDesc('updated_at')
            ->limit($candidateLimit)
            ->get(['source', 'content', 'metadata', 'embedding']);

        if ($candidates->isEmpty()) {
            return collect();
        }

        $scored = $candidates->map(function (RagEmbedding $row) use ($embedding) {
            $vec = is_array($row->embedding) ? $row->embedding : [];
            $score = $this->cosineSimilarity($embedding, $vec);

            return [
                'source' => (string) $row->source,
                'content' => (string) $row->content,
                'metadata' => is_array($row->metadata) ? $row->metadata : null,
                'score' => $score,
            ];
        });

        return $scored
            ->sortByDesc('score')
            ->take($topK)
            ->values();
    }

    /**
     * @param  array<int, float|int>  $a
     * @param  array<int, float|int>  $b
     */
    protected function cosineSimilarity(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
