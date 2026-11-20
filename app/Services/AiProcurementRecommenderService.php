<?php

namespace App\Services;

use App\Models\AiProcurementItem;
use App\Models\AiProcurementRun;
use App\Support\StockCoverPhrase;
use Illuminate\Support\Collection;

class AiProcurementRecommenderService
{
    public const HELPER_KEY = 'ai_recommendation';

    public const FLAG_LABELS = [
        'below_reorder' => 'Below reorder',
        'review_forecast' => 'Review forecast',
        'file_pr_soon' => 'File PR soon',
    ];

    /** Flags shown as Why chips (below_reorder stays in data for AI only). */
    public const WHY_DISPLAY_FLAGS = [
        'review_forecast',
        'file_pr_soon',
    ];

    public const SEMI_NEXT_ACTION_FALLBACK = 'Review semi-expendable units due for replacement this cycle, then follow the next step shown on each card.';

    /**
     * Phrases that claim a flag; Why may use them only when that flag is on the item.
     * Plain "below reorder" is allowed as stock language; snake_case tokens are checked separately.
     *
     * @var array<string, list<string>>
     */
    protected const FLAG_CLAIM_PHRASES = [
        'review_forecast' => [
            'review forecast',
            'inflating forecast',
            'large issuance',
            'usage spike',
            'spike share',
        ],
        'file_pr_soon' => [
            'file pr soon',
            'procurement buffer',
            'buffer days',
        ],
    ];

    public function __construct(
        protected OllamaClient $ollama,
        protected AiProcurementHelperService $helpers,
    ) {}

    /**
     * Rank High consumables and write next_action + per-item Why + semi_next_action (numbers stay locked).
     *
     * @return array{
     *   next_action: string,
     *   ranked_high_item_ids: list<int>,
     *   why_by_item_id: array<string, string>,
     *   flags_by_item_id: array<string, list<string>>,
     *   semi_next_action: string,
     *   source: string
     * }
     */
    public function recommend(AiProcurementRun $run): array
    {
        $run->loadMissing('items');
        $highs = $this->highConsumableItems($run);
        $semis = $this->replacementItems($run);

        if ($highs->isEmpty() && $semis->isEmpty()) {
            return $this->fallback(
                $highs,
                $semis,
                'No High-priority consumables need attention this cycle.',
            );
        }

        if (! $this->ollama->isAvailable()) {
            return $this->fallback(
                $highs,
                $semis,
                $highs->isEmpty() ? 'No High-priority consumables need attention this cycle.' : null,
            );
        }

        $candidates = $highs->map(fn (AiProcurementItem $item): array => $this->candidateFacts($item))->values()->all();
        $semiCandidates = $semis->map(fn (AiProcurementItem $item): array => $this->semiCandidateFacts($item))->values()->all();
        $raw = $this->ollama->chat(
            $this->systemPrompt(),
            $this->userPrompt($candidates, $semiCandidates),
            $this->chatOptions(),
        );

        if (! filled($raw)) {
            return $this->fallback($highs, $semis);
        }

        $parsed = $this->parseJson((string) $raw);
        if ($parsed === null) {
            return $this->fallback($highs, $semis);
        }

        return $this->validateAndMerge($highs, $semis, $parsed);
    }

    /**
     * @param  Collection<int, AiProcurementItem>  $highs
     * @param  Collection<int, AiProcurementItem>  $semis
     * @return array{next_action: string, ranked_high_item_ids: list<int>, why_by_item_id: array<string, string>, flags_by_item_id: array<string, list<string>>, semi_next_action: string, source: string}
     */
    public function fallback(Collection $highs, ?Collection $semis = null, ?string $nextAction = null): array
    {
        $semis ??= collect();

        return [
            'next_action' => $nextAction ?? 'Review High-priority consumables first, then prepare what to buy next.',
            'ranked_high_item_ids' => $this->stableHighIds($highs),
            'why_by_item_id' => [],
            'flags_by_item_id' => $this->flagsByItemId($highs),
            'semi_next_action' => $semis->isEmpty() ? '' : self::SEMI_NEXT_ACTION_FALLBACK,
            'source' => 'fallback',
        ];
    }

    /**
     * @param  Collection<int, AiProcurementItem>  $highs
     * @param  Collection<int, AiProcurementItem>  $semis
     * @param  array<string, mixed>  $parsed
     * @return array{next_action: string, ranked_high_item_ids: list<int>, why_by_item_id: array<string, string>, flags_by_item_id: array<string, list<string>>, semi_next_action: string, source: string}
     */
    public function validateAndMerge(Collection $highs, Collection $semis, array $parsed): array
    {
        $allowedIds = $this->stableHighIds($highs);
        $allowedSet = array_fill_keys($allowedIds, true);

        $ranked = [];
        foreach ($parsed['ranked_high_item_ids'] ?? [] as $id) {
            $id = (int) $id;
            if (isset($allowedSet[$id]) && ! in_array($id, $ranked, true)) {
                $ranked[] = $id;
            }
        }
        foreach ($allowedIds as $id) {
            if (! in_array($id, $ranked, true)) {
                $ranked[] = $id;
            }
        }

        $byId = $highs->keyBy(fn (AiProcurementItem $item): int => (int) $item->item_id);
        $whyById = [];
        $rawWhy = is_array($parsed['why_by_item_id'] ?? null) ? $parsed['why_by_item_id'] : [];

        foreach ($rawWhy as $key => $text) {
            $id = $this->resolveWhyKeyToItemId($key, $byId, $allowedSet);
            if ($id === null) {
                continue;
            }
            $item = $byId->get($id);
            if ($item === null) {
                continue;
            }
            $why = $this->replaceProseItemIds(trim((string) $text), $byId);
            if (
                $why === ''
                || $this->isMetaInstructionProse($why)
                || ! $this->whyUsesOnlyAllowedFacts($why, $item)
                || ! $this->whyUsesOnlyAllowedFlags($why, $item)
            ) {
                continue;
            }
            $whyById[(string) $id] = $why;
        }

        if ($highs->isEmpty()) {
            $nextAction = 'No High-priority consumables need attention this cycle.';
        } else {
            $nextAction = $this->replaceProseItemIds(
                trim((string) ($parsed['next_action'] ?? '')),
                $byId,
            );
            if (
                $nextAction === ''
                || $this->proseMentionsRawItemId($nextAction)
                || $this->isMetaInstructionProse($nextAction)
            ) {
                $nextAction = 'Review High-priority consumables first, then prepare what to buy next.';
            }
            $nextAction = $this->enrichNextAction($nextAction, $byId, $ranked);
        }

        $semiNextAction = $this->normalizeSemiNextAction(
            trim((string) ($parsed['semi_next_action'] ?? '')),
            $semis,
        );

        return [
            'next_action' => $nextAction,
            'ranked_high_item_ids' => $ranked,
            'why_by_item_id' => $whyById,
            'flags_by_item_id' => $this->flagsByItemId($highs),
            'semi_next_action' => $semiNextAction,
            'source' => 'ai',
        ];
    }

    /**
     * @param  Collection<int, AiProcurementItem>  $semis
     */
    protected function normalizeSemiNextAction(string $semiNextAction, Collection $semis): string
    {
        if ($semis->isEmpty()) {
            return '';
        }

        if (
            $semiNextAction === ''
            || $this->proseMentionsRawItemId($semiNextAction)
            || $this->isMetaInstructionProse($semiNextAction)
        ) {
            return self::SEMI_NEXT_ACTION_FALLBACK;
        }

        return $semiNextAction;
    }

    /**
     * @param  Collection<int, AiProcurementItem>  $highs
     * @return array<string, list<string>>
     */
    public function flagsByItemId(Collection $highs): array
    {
        $out = [];
        foreach ($highs as $item) {
            $id = (int) $item->item_id;
            if ($id === 0) {
                continue;
            }
            $flags = is_array($item->insight_flags) ? array_values($item->insight_flags) : [];
            $out[(string) $id] = $flags;
        }

        return $out;
    }

    /**
     * @param  Collection<int, AiProcurementItem>  $byId
     * @param  array<int, true>  $allowedSet
     */
    protected function resolveWhyKeyToItemId(mixed $key, Collection $byId, array $allowedSet): ?int
    {
        $id = (int) $key;
        if (isset($allowedSet[$id])) {
            return $id;
        }

        $needle = strtolower(trim((string) $key));
        if ($needle === '') {
            return null;
        }

        foreach ($byId as $itemId => $item) {
            if (strtolower((string) $item->item_name) === $needle) {
                return (int) $itemId;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, AiProcurementItem>  $byId
     * @param  list<int>  $ranked
     */
    protected function enrichNextAction(string $nextAction, Collection $byId, array $ranked): string
    {
        $words = str_word_count($nextAction);
        if ($words >= 22) {
            return $nextAction;
        }

        $names = [];
        foreach ($ranked as $id) {
            $item = $byId->get($id);
            if ($item === null) {
                continue;
            }
            $names[] = (string) $item->item_name;
            if (count($names) >= 3) {
                break;
            }
        }

        if ($names === []) {
            return $nextAction;
        }

        $list = match (count($names)) {
            1 => $names[0],
            2 => $names[0].' and '.$names[1],
            default => $names[0].', '.$names[1].', and '.$names[2],
        };

        $suffix = "Focus the purchase request on {$list}, using the suggested buy quantities in What to buy next.";
        if (str_contains(strtolower($nextAction), strtolower($names[0]))) {
            return rtrim($nextAction, " \t.").'. '.$suffix;
        }

        return rtrim($nextAction, " \t.").'. '.$suffix;
    }

    /**
     * @param  Collection<int, AiProcurementItem>  $byId
     */
    protected function replaceProseItemIds(string $text, Collection $byId): string
    {
        if ($text === '') {
            return $text;
        }

        $replaced = preg_replace_callback(
            '/\bitem[_\s-]?id\s*[:=]?\s*(\d+)\b/i',
            function (array $matches) use ($byId): string {
                $id = (int) $matches[1];
                $item = $byId->get($id);

                return $item !== null ? (string) $item->item_name : $matches[0];
            },
            $text,
        );

        return trim((string) $replaced);
    }

    protected function proseMentionsRawItemId(string $text): bool
    {
        return preg_match('/\bitem[_\s-]?id\b/i', $text) === 1;
    }

    protected function isMetaInstructionProse(string $text): bool
    {
        $lower = strtolower($text);

        foreach ([
            'two sentences',
            'naming the top',
            'custodian next step',
            'one sentence using',
            "item's facts only",
            'using that item',
            'return json',
            'this shape',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, AiProcurementItem>
     */
    protected function highConsumableItems(AiProcurementRun $run): Collection
    {
        return $run->items
            ->filter(fn (AiProcurementItem $item): bool => $item->section !== 'replacement'
                && (string) $item->priority === 'High'
                && $item->item_id !== null)
            ->sortBy([
                fn (AiProcurementItem $item): string => strtolower((string) $item->item_name),
                fn (AiProcurementItem $item): int => (int) $item->item_id,
            ])
            ->values();
    }

    /**
     * @return Collection<int, AiProcurementItem>
     */
    protected function replacementItems(AiProcurementRun $run): Collection
    {
        return $run->items
            ->filter(fn (AiProcurementItem $item): bool => $item->section === 'replacement')
            ->sortBy([
                fn (AiProcurementItem $item): string => strtolower((string) $item->item_name),
                fn (AiProcurementItem $item): string => strtolower((string) ($item->property_number ?? '')),
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function semiCandidateFacts(AiProcurementItem $item): array
    {
        return [
            'name' => (string) $item->item_name,
            'property_number' => (string) ($item->property_number ?? ''),
            'eul_status' => (string) ($item->eul_status ?? ''),
            'next_step' => $this->helpers->nextStepForReplacement($item),
            'life_remaining' => $this->helpers->lifeRemainingPhrase(
                $item->eul_days_remaining !== null ? (int) $item->eul_days_remaining : null,
            ),
            'estimated_useful_life' => (string) ($item->estimated_useful_life ?? ''),
        ];
    }

    /**
     * @param  Collection<int, AiProcurementItem>  $highs
     * @return list<int>
     */
    protected function stableHighIds(Collection $highs): array
    {
        return $highs
            ->map(fn (AiProcurementItem $item): int => (int) $item->item_id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function candidateFacts(AiProcurementItem $item): array
    {
        $cover = $item->months_cover !== null
            ? StockCoverPhrase::fromMonths((float) $item->months_cover)
            : '';

        $flags = is_array($item->insight_flags) ? array_values($item->insight_flags) : [];

        return [
            'item_id' => (int) $item->item_id,
            'name' => (string) $item->item_name,
            'stock' => $item->current_stock !== null ? (int) $item->current_stock : null,
            'reorder_level' => $this->reorderLevelFromReason((string) $item->reason),
            'forecast' => $item->avg_monthly_usage !== null ? (float) $item->avg_monthly_usage : null,
            'cover_phrase' => $cover,
            'suggested' => $item->suggested_qty_min !== null ? (int) $item->suggested_qty_min : null,
            'flags' => $flags,
            'spike_share' => $item->spike_share !== null ? (float) $item->spike_share : null,
            'buffer_days' => max(1, (int) config('services.procurement.buffer_days', 60)),
        ];
    }

    protected function reorderLevelFromReason(string $reason): ?int
    {
        if (preg_match('/reorder\s+(\d+)/i', $reason, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @return array{temperature: float}
     */
    protected function chatOptions(): array
    {
        $temperature = (float) config('services.ollama.chat_temperature', 0.4);

        return [
            'temperature' => max(0.0, min(1.0, $temperature)),
        ];
    }

    protected function phrasingStyleHint(): string
    {
        $styles = [
            'Write as a direct custodian briefing: name the first High, then the PR package.',
            'Write as a short urgency note for the requisitioner before filing.',
            'Write as a calm planning reminder that still states which High comes first.',
            'Write by contrasting the top High against the next High in one clear priority call.',
        ];

        return $styles[array_rand($styles)];
    }

    protected function systemPrompt(): string
    {
        return <<<'TXT'
You are a procurement recommender for OWWA Regional Office IV-A.
Read the candidate facts and reply with JSON only (no markdown).
Keys: next_action, ranked_high_item_ids, why_by_item_id, semi_next_action.
next_action: exactly two real sentences for High consumables (priority + prepare a PR). Mention review_forecast items before locking quantities. Use item names only — never "item_id" or raw IDs in prose.
ranked_high_item_ids: every High item_id once, most urgent first (prefer review_forecast and thinner cover). Empty array if no Highs.
why_by_item_id: one real why sentence per High item_id key. Explain urgency difference and flags (review_forecast, file_pr_soon, below_reorder). Do not open with a stock/reorder/cover lecture; Summary already shows those numbers. Use numbers only when needed for a flag claim. Do not invent quantities or flags.
semi_next_action: exactly two real sentences for semi-expendable units when provided. Use names and the given next_step values only. Never invent Issue vs Purchase. Empty string if no semi candidates.
Vary the wording of next_action, why_by_item_id, and semi_next_action on every run. Keep the same facts, item names, flags, and ranking intent. Do not paste the example reply verbatim.
TXT;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array<string, mixed>>  $semiCandidates
     */
    protected function userPrompt(array $candidates, array $semiCandidates = []): string
    {
        $json = json_encode([
            'high_consumables' => $candidates,
            'semi_expendable' => $semiCandidates,
        ], JSON_UNESCAPED_UNICODE);
        $example = $this->filledExampleJson($candidates, $semiCandidates);
        $style = $this->phrasingStyleHint();

        return <<<TXT
Candidates (JSON):
{$json}

Phrasing style for this run (facts stay the same; paraphrase only): {$style}

Example of a correct reply shape (use the real candidates above; do not reuse this sample wording):
{$example}

Reply with JSON only for the candidates above. Every High item_id must appear in ranked_high_item_ids and why_by_item_id when Highs exist. Include semi_next_action when semi_expendable is non-empty. Do not copy instruction text into next_action, why, or semi_next_action.
TXT;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array<string, mixed>>  $semiCandidates
     */
    protected function filledExampleJson(array $candidates, array $semiCandidates = []): string
    {
        $first = $candidates[0] ?? null;
        $second = $candidates[1] ?? null;
        $semiFirst = $semiCandidates[0] ?? null;
        $semiNext = '';
        if (is_array($semiFirst)) {
            $semiName = (string) ($semiFirst['name'] ?? 'Unit');
            $semiStep = (string) ($semiFirst['next_step'] ?? 'follow the listed next step');
            $semiNext = "Prioritize {$semiName} first. {$semiStep} as shown for that unit.";
        }

        if (! is_array($first)) {
            return (string) json_encode([
                'next_action' => 'No High-priority consumables need attention this cycle.',
                'ranked_high_item_ids' => [],
                'why_by_item_id' => new \stdClass,
                'semi_next_action' => $semiNext !== '' ? $semiNext : '',
            ], JSON_UNESCAPED_UNICODE);
        }

        $id1 = (int) ($first['item_id'] ?? 0);
        $name1 = (string) ($first['name'] ?? 'Item');
        $cover1 = (string) ($first['cover_phrase'] ?? 'thin cover');

        $flags1 = is_array($first['flags'] ?? null) ? $first['flags'] : [];
        if (in_array('review_forecast', $flags1, true)) {
            $why1 = 'Urgency is high because one large issuance may be inflating forecast; confirm usage before locking the PR.';
        } elseif (in_array('file_pr_soon', $flags1, true)) {
            $why1 = "File a PR soon — cover is only {$cover1}, inside the procurement buffer.";
        } else {
            $why1 = 'Highest urgency among Highs because cover is already thin versus ongoing requests.';
        }

        $ranked = [$id1];
        $whyById = [(string) $id1 => $why1];
        $next = "Prioritize {$name1} first given its thin cover and High priority.";
        if (in_array('review_forecast', $flags1, true)) {
            $next = "Review the forecast for {$name1} before locking the PR because one large issuance may be inflating usage.";
        }

        if (is_array($second)) {
            $id2 = (int) ($second['item_id'] ?? 0);
            $name2 = (string) ($second['name'] ?? 'Item');
            $cover2 = (string) ($second['cover_phrase'] ?? 'thin cover');
            $flags2 = is_array($second['flags'] ?? null) ? $second['flags'] : [];
            $ranked[] = $id2;
            if (in_array('file_pr_soon', $flags2, true)) {
                $whyById[(string) $id2] = "Also due soon — only {$cover2} of cover remains inside the buffer.";
            } else {
                $whyById[(string) $id2] = 'Next in priority after the top High; keep it in the same PR package.';
            }
            $next .= " Prepare one PR covering {$name1} and {$name2} using the suggested buy quantities.";
        } else {
            $next .= ' Prepare one PR for this High item using the suggested buy quantity.';
        }

        foreach ($candidates as $row) {
            $id = (int) ($row['item_id'] ?? 0);
            if ($id === 0 || isset($whyById[(string) $id])) {
                continue;
            }
            $ranked[] = $id;
            $cover = (string) ($row['cover_phrase'] ?? 'limited cover');
            $whyById[(string) $id] = "Include in the same cycle while cover is only {$cover}.";
        }

        return (string) json_encode([
            'next_action' => $next,
            'ranked_high_item_ids' => $ranked,
            'why_by_item_id' => $whyById,
            'semi_next_action' => $semiNext,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseJson(string $raw): ?array
    {
        $text = trim($raw);
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $matches) === 1) {
            $text = $matches[1];
        } elseif (preg_match('/\{.*\}/s', $text, $matches) === 1) {
            $text = $matches[0];
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function whyUsesOnlyAllowedFacts(string $why, AiProcurementItem $item): bool
    {
        preg_match_all('/\d+(?:\.\d+)?/', $why, $matches);
        $digits = $matches[0] ?? [];
        if ($digits === []) {
            return true;
        }

        $allowed = $this->allowedFactTokens($item);

        foreach ($digits as $token) {
            if (! in_array($token, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    protected function whyUsesOnlyAllowedFlags(string $why, AiProcurementItem $item): bool
    {
        $itemFlags = is_array($item->insight_flags) ? $item->insight_flags : [];
        $lower = strtolower($why);

        foreach (array_keys(self::FLAG_LABELS) as $flag) {
            if (! in_array($flag, $itemFlags, true) && str_contains($lower, $flag)) {
                return false;
            }
        }

        foreach (self::FLAG_CLAIM_PHRASES as $flag => $phrases) {
            if (in_array($flag, $itemFlags, true)) {
                continue;
            }
            foreach ($phrases as $phrase) {
                if (str_contains($lower, $phrase)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    protected function allowedFactTokens(AiProcurementItem $item): array
    {
        $tokens = [];
        foreach ([
            $item->current_stock,
            $item->suggested_qty_min,
            $item->avg_monthly_usage,
            $item->months_cover,
            $item->spike_share,
            $this->reorderLevelFromReason((string) $item->reason),
            config('services.procurement.buffer_days', 60),
        ] as $value) {
            if ($value === null) {
                continue;
            }
            $tokens[] = (string) (int) $value;
            $tokens[] = number_format((float) $value, 1, '.', '');
            $tokens[] = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
            $tokens[] = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }

        preg_match_all('/\d+(?:\.\d+)?/', (string) $item->item_name, $nameDigits);
        foreach ($nameDigits[0] ?? [] as $token) {
            $tokens[] = $token;
        }

        $cover = $item->months_cover !== null
            ? StockCoverPhrase::fromMonths((float) $item->months_cover)
            : '';
        preg_match_all('/\d+(?:\.\d+)?/', $cover, $coverDigits);
        foreach ($coverDigits[0] ?? [] as $token) {
            $tokens[] = $token;
        }

        preg_match_all('/\d+(?:\.\d+)?/', (string) $item->reason, $reasonDigits);
        foreach ($reasonDigits[0] ?? [] as $token) {
            $tokens[] = $token;
        }

        return array_values(array_unique(array_filter($tokens, fn (string $t): bool => $t !== '')));
    }
}
