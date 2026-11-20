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
     * Generate a decision-support narrative for a deterministic recommendation set.
     *
     * @param  array{from:string,to:string,category:string|null,pairs:int,high:int,medium:int,headline?:string,replacement_count?:int,replacement_lines?:string}  $facts
     * @param  array<int, array{priority:string,item:string,cover:float,forecast:float,suggested:int|null,stock?:int|string,reorder_level?:int|string}>  $items
     * @param  array{category_id?:int|null}  $filters
     * @return array{narrative: string, policy_snippets: list<array{source: string, content: string}>}|null
     */
    public function generateNarrativeSummary(array $facts, array $items, array $filters = []): ?array
    {
        if (! $this->ollama->isAvailable()) {
            return null;
        }

        $systemPrompt = 'You are a procurement advisor for OWWA Regional Office IV-A. Use only the facts provided. Do not invent numbers or causes. Prioritize High-risk consumables first. Never suggest deprioritizing High-risk items. Write clear plain prose for supply custodians. Never append office or location after an item name, and never use "@". Never write decimal months such as 0.61 months; use only the provided cover phrases in days, weeks, or whole months. Keep categories separate: consumables are stock reorders only and have no replacement or useful-life review; semi-expendable units are replacement or issue-from-stock reviews only. Never say consumables are due for replacement.';

        $itemsText = $this->formatItemLines($items);

        $categoryLine = $facts['category'] ? "Category scope: {$facts['category']}." : 'Category scope: All categories.';
        $headlineLine = filled($facts['headline'] ?? null) ? "Summary headline: {$facts['headline']}." : '';
        $replacementCount = (int) ($facts['replacement_count'] ?? 0);
        $replacementLines = (string) ($facts['replacement_lines'] ?? 'none');

        $retrieved = $this->retrieval->retrieve(
            query: 'procurement recommendations '.($facts['category'] ?? 'all categories'),
            filters: $filters,
            topK: 12,
            candidateLimit: 400,
        );

        $policySnippets = $retrieved
            ->map(fn (array $c): array => [
                'source' => (string) ($c['source'] ?? 'policy'),
                'content' => mb_substr((string) ($c['content'] ?? ''), 0, 220),
            ])
            ->filter(fn (array $c): bool => $c['content'] !== '')
            ->values()
            ->all();

        $retrievedText = $policySnippets !== []
            ? ("\n\nRetrieved policy/context snippets:\n".collect($policySnippets)
                ->map(fn (array $c): string => "- ({$c['source']}) {$c['content']}")
                ->implode("\n"))
            : '';

        $userMessage = <<<TXT
Period covered: {$facts['from']} to {$facts['to']}.
{$categoryLine}
{$headlineLine}
Consumable reorder pairs: {$facts['pairs']} (High: {$facts['high']}, Medium: {$facts['medium']}).

Consumable reorders (pre-computed purchases). Use stock, reorder point, forecast (units/mo), and the given cover phrase only for these rows:
{$itemsText}

Semi-expendable replacement reviews: {$replacementCount}. These are not reorders. Do not apply reorder point, forecast, or cover language to them. Do not turn "Issue from stock" or "Awaiting review" into a purchase quantity. A purchase applies only when the action is Purchase.
{$replacementLines}
{$retrievedText}

Return exactly three labeled blocks and nothing else. Do not output a table. Do not wrap the answer in quotation marks.
Never echo instructions or scaffolding. Forbidden text includes: "One opening line", "Sentence 1", "Sentence pattern", "High:", "Medium:", "Low:".
Never write decimal months (for example 0.61 months). Copy the cover phrase as given (days, weeks, or whole months).

Format exactly:

[[NEXT ACTION]]
One short line naming the next operational step this cycle (for example prepare what to buy next, issue-from-stock first, or wait). Do not invent quantities.

[[CONSUMABLES]]
Markdown bullets only for consumable stock gaps. One bullet per item using this pattern: **Item** — stock N → buy N · cover phrase. Bold the item name. List High items first, then Medium if present. Do not write long paragraphs. Do not discuss semi-expendable units here. Never mention replacement, expiry, useful life, or "due for replacement" for consumables. If High consumable rows are listed above, you must include a bullet for each; do not claim that no High consumables need attention.

[[SEMI-EXPENDABLE]]
Markdown bullets only for semi-expendable units. One bullet per unit using: **Item** (property) — EUL status → next step. Examples of next step: Issue from stock, Purchase, or Waiting for holder review. Never write "status: Awaiting review". Never invent a reorder quantity, and never use forecast, cover, or reorder language. Never use the word "consumables" in this block. Never write that consumables are due for replacement. If Semi-expendable replacement reviews is 0 or the list is none, write exactly: No semi-expendable units are due for replacement review this cycle. If units are listed, bullet them; do not claim that none are due.

If policy/context snippets were provided, weave at most one short policy-aligned reason into [[NEXT ACTION]] without quoting the snippet verbatim at length.

Never append office or location. Never use "@".
TXT;

        $narrative = $this->ollama->chat($systemPrompt, $userMessage);

        if (! filled($narrative)) {
            return null;
        }

        return [
            'narrative' => $narrative,
            'policy_snippets' => $policySnippets,
        ];
    }

    /**
     * @param  array<int, array{priority:string,item:string,cover?:float,forecast?:float,suggested?:int|null,stock?:int|string,reorder_level?:int|string}>  $items
     */
    public function formatItemLines(array $items): string
    {
        return collect($items)
            ->map(function (array $i): string {
                $suggested = $i['suggested'] ?? 0;
                $stock = $i['stock'] ?? '—';
                $reorder = $i['reorder_level'] ?? '—';
                $coverPhrase = \App\Support\StockCoverPhrase::fromMonths((float) ($i['cover'] ?? 0));
                $forecast = $i['forecast'] ?? 0;

                return "- {$i['priority']}: {$i['item']} (stock {$stock}, reorder point {$reorder}, cover {$coverPhrase}, forecast {$forecast} units/mo, suggested buy {$suggested})";
            })
            ->implode("\n");
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
- Consumable rows use months of cover and reorder point. Semi-expendable rows are replacement reviews, not reorders. Do not apply reorder point or months of cover to a semi-expendable unit, and do not turn "Issue from stock" or "Awaiting review" into a purchase quantity.
- Consider a consumable at risk when its estimated months of cover is LESS than 3 months OR when stock is at or below the reorder_point.
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
