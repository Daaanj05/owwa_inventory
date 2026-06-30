<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use Illuminate\Support\Facades\DB;

class RagService
{
    public function __construct(
        protected OllamaClient $ollama,
        protected InventoryStockService $stock,
        protected RagRetrievalService $retrieval,
    ) {}

    /**
     * Generate a short narrative summary for a deterministic, pre-computed recommendation set.
     *
     * @param  array{from:string,to:string,category:string|null,pairs:int,high:int,medium:int,headline?:string}  $facts
     * @param  array<int, array{priority:string,item:string,office:string,cover:float,forecast:float,suggested:int|null,has_recent_usage?:bool}>  $items
     */
    public function generateNarrativeSummary(array $facts, array $items, array $filters = []): ?string
    {
        if (! $this->ollama->isAvailable()) {
            return null;
        }

        $systemPrompt = 'You are a procurement advisor for OWWA Regional Office IV-A. Use only the facts provided. Do not invent numbers. Prioritize High-risk items first. Never suggest deprioritizing High-risk items. Keep the summary to at most 2 sentences.';

        $itemsText = collect($items)
            ->take(8)
            ->map(function (array $i): string {
                $suggested = $i['suggested'] ?? 0;
                $usageNote = ($i['has_recent_usage'] ?? true) ? '' : ', no recent usage';

                return "- {$i['priority']}: {$i['item']} @ {$i['office']} (cover {$i['cover']} mo, forecast {$i['forecast']}/mo, suggested {$suggested}{$usageNote})";
            })
            ->implode("\n");

        $categoryLine = $facts['category'] ? "Category scope: {$facts['category']}." : 'Category scope: All categories.';
        $headlineLine = filled($facts['headline'] ?? null) ? "Summary headline: {$facts['headline']}." : '';

        $retrieved = $this->retrieval->retrieve(
            query: 'procurement recommendations '.($facts['category'] ?? 'all categories'),
            filters: $filters,
            topK: 12,
            candidateLimit: 400,
        );

        $retrievedText = $retrieved->isNotEmpty()
            ? ("\n\nRetrieved context snippets:\n".$retrieved->map(fn ($c) => "- ({$c['source']}) ".mb_substr($c['content'], 0, 180))->implode("\n"))
            : '';

        $userMessage = <<<TXT
Period covered: {$facts['from']} to {$facts['to']}.
{$categoryLine}
{$headlineLine}
At-risk pairs: {$facts['pairs']} (High: {$facts['high']}, Medium: {$facts['medium']}).

Top at-risk items (pre-computed):
{$itemsText}
{$retrievedText}

Write at most 2 sentences. Lead with High-priority reorders and suggested quantities. Do not output a table.
TXT;

        return $this->ollama->chat($systemPrompt, $userMessage);
    }

    /**
     * Build inventory/consumption context for the last N months.
     *
     * For the thesis design, we consider up to ~5 years of history,
     * so typical calls use 60 months (5 years) as the upper window.
     *
     * @param  int  $months  Max months of history to consider
     * @param  int|null  $categoryId  If set, only include items in this item category (from item_categories)
     */
    public function buildInventoryContext(int $months = 6, ?int $categoryId = null): string
    {
        $fromDate = now()->subMonths($months);
        $toDate = now();

        $issuanceQuery = Issuance::query()
            ->whereBetween('issuance_date', [$fromDate, $toDate]);

        if ($categoryId !== null) {
            $itemIds = Item::where('item_category_id', $categoryId)->pluck('id')->toArray();
            $issuanceQuery->whereIn('item_id', $itemIds);
        }

        $oldest = (clone $issuanceQuery)->min('issuance_date');
        $newest = (clone $issuanceQuery)->max('issuance_date');

        if ($oldest && $newest) {
            $from = \Carbon\Carbon::parse($oldest);
            $to = \Carbon\Carbon::parse($newest);
        } else {
            $from = $fromDate;
            $to = $toDate;
        }

        $fromLabel = $from->format('F Y');
        $toLabel = $to->format('F Y');
        $rangeLabel = "{$fromLabel} to {$toLabel}";
        $monthsSpan = max(1, (int) $from->diffInMonths($to) + 1);

        $issuances = $issuanceQuery
            ->with(['item', 'office'])
            ->select(['item_id', 'office_id', DB::raw('SUM(quantity) as total')])
            ->groupBy('item_id', 'office_id')
            ->get();

        // Preload item categories so the AI can reason by category
        // (e.g. Consumables vs PPE / Safety vs Semi‑Expendable).
        $itemCategories = DB::table('items')
            ->leftJoin('item_categories', 'items.item_category_id', '=', 'item_categories.id')
            ->pluck('item_categories.name', 'items.id')
            ->toArray();

        $categoryName = $categoryId !== null
            ? (ItemCategory::find($categoryId)?->name ?? 'Unknown')
            : null;

        $lines = [
            '## Inventory risk metrics ('.$rangeLabel.')',
            "Approximate period length: {$monthsSpan} months",
        ];
        if ($categoryName !== null) {
            $lines[] = "User-selected category filter: {$categoryName}. Only items in this category are included below.";
        }

        foreach ($issuances as $row) {
            $item = Item::find($row->item_id);
            $office = Office::find($row->office_id);
            $name = $item?->name ?? "Item#{$row->item_id}";
            $officeName = $office?->name ?? "Office#{$row->office_id}";
            $categoryName = $itemCategories[$row->item_id] ?? 'Uncategorized';

            $avgPerMonth = $monthsSpan > 0 ? $row->total / $monthsSpan : null;
            $stock = $this->stock->getStock($row->item_id, $row->office_id);

            $monthsCover = null;
            if ($avgPerMonth !== null && $avgPerMonth > 0 && $item && $item->reorder_level > 0) {
                $monthsCover = $stock / $avgPerMonth;
            }

            $lines[] = sprintf(
                '- %s (%s) at %s — issued %d units over ~%d months (≈ %s per month). Current stock: %d units; reorder point: %d; estimated cover: %s months.',
                $name,
                $categoryName ?: 'Uncategorized',
                $officeName,
                (int) $row->total,
                $monthsSpan,
                $avgPerMonth !== null ? number_format($avgPerMonth, 2, '.', '') : 'N/A',
                (int) $stock,
                $item?->reorder_level ?? 0,
                $monthsCover !== null ? number_format($monthsCover, 2, '.', '') : 'N/A'
            );
        }

        $lines[] = "\n## Current stock and reorder points";
        foreach ($this->stock->getStockLevelsList($categoryId) as $position) {
            $status = $position->is_low ? 'LOW' : 'OK';
            $lines[] = "- {$position->item_name} at {$position->office_name}: stock={$position->stock}, reorder_point={$position->reorder_level} ({$status})";
        }

        return implode("\n", $lines);
    }

    /**
     * Get procurement recommendation using Neuron AI agent (when available) or fallback to direct Ollama.
     *
     * @param  string|null  $query  Override prompt (optional)
     * @param  int|null  $categoryId  If set, context and recommendations are limited to this item category
     */
    public function getRecommendation(?string $query = null, ?int $categoryId = null): ?string
    {
        $query ??= <<<'MD'
You are advising procurement for OWWA Regional Office IV-A.

1) First write a short summary (2–3 sentences) describing:
- The overall risk level (e.g. no urgent issues / some items at risk),
- The approximate historical period covered (based on the context),
- Any high-level observations (e.g. which offices or item types stand out).

2) Then output a single markdown table listing ONLY items that are at risk of running low, using this exact header row and column order:

| Priority | Item | Department/Office | Current stock | Avg/month | Months of cover | Suggested reorder | Reason |

You will see risk metrics in the context like:
- total_issued
- avg_per_month
- stock
- reorder_point
- months_cover (sometimes "N/A" when not available)

Rules for the table:
- Consider an item at risk when its estimated months of cover is LESS than 3 months OR when stock is at or below the reorder_point.
- When months_cover is given, use it. When it is missing, estimate it as stock divided by avg_per_month if possible; if that is still not possible, write "N/A" in the column but still include clearly low-stock items.
- Use Priority = "High" when months of cover < 1 (or stock is critically below reorder_point), "Medium" when 1–3, and "Low" otherwise (omit Low from the table).
- Include at most 10 rows; choose the most critical items only.
- Suggested reorder may be a range like "80–100" or a single number.
- Keep the Reason column to one short sentence. Use the exact Current stock and reorder point numbers from that row; do not invent different numbers.

If there are no items at risk, clearly say so in the summary and do NOT output the table.
MD;

        if ($categoryId !== null) {
            $catName = ItemCategory::find($categoryId)?->name ?? 'Selected category';
            $query .= "\n\nImportant: The user selected category filter \"{$catName}\". Only recommend items that belong to this category; ignore any other items in the context.";
        }

        // Use up to 5 years (~60 months) of historical issuances for context.
        $context = $this->buildInventoryContext(60, $categoryId);

        // Prefer Neuron AI agent when the package is installed
        if (class_exists(\App\Neuron\ProcurementAgent::class)) {
            $recommendation = \App\Neuron\ProcurementAgent::make()->recommend($context, $query);
            if ($recommendation !== null && $recommendation !== '') {
                return $recommendation;
            }
        }

        // Fallback to direct Ollama client
        if (! $this->ollama->isAvailable()) {
            return null;
        }

        $systemPrompt = 'You are a procurement advisor for OWWA Regional Office IV-A. Use only the provided inventory and stock data. Give short, evidence-based reorder recommendations.';
        $userMessage = "Context:\n".$context."\n\nQuestion: ".$query;

        return $this->ollama->chat($systemPrompt, $userMessage);
    }
}
