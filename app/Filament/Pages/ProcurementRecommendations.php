<?php

namespace App\Filament\Pages;

use App\Models\AiProcurementItem;
use App\Models\AiProcurementRun;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\RagService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use UnitEnum;

class ProcurementRecommendations extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-light-bulb';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'Procurement recommendations';

    protected static ?string $title = 'Procurement recommendations';

    protected string $view = 'filament.pages.procurement-recommendations';

    public ?string $recommendation = null;

    public bool $loading = false;

    /** Selected item category ID for filtering recommendations. Empty string = All categories. */
    public string $categoryId = '';

    public function getTitle(): string
    {
        return 'Procurement recommendations';
    }

    /** Categories from item_categories; used for the filter dropdown (auto-updates when new categories are added). */
    public function getItemCategories(): \Illuminate\Support\Collection
    {
        return ItemCategory::orderBy('name')->get();
    }

    public static function getNavigationLabel(): string
    {
        return 'Procurement recommendations';
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate recommendation')
                ->icon('heroicon-o-sparkles')
                ->action(function (): void {
                    $this->loading = true;
                    $this->recommendation = null;

                    // LLM can take 60–120 seconds; avoid "Maximum execution time exceeded"
                    set_time_limit(120);

                    try {
                        $categoryId = $this->categoryId !== '' && $this->categoryId !== null
                            ? (int) $this->categoryId
                            : null;
                        $raw = app(RagService::class)->getRecommendation(null, $categoryId);

                        if (! $raw) {
                            $this->recommendation = 'Ollama is not available. Start Ollama and ensure the embed and chat models are installed.';
                        } else {
                            $this->recommendation = $raw;
                            $this->storeAiProcurementRun($raw);
                        }
                    } catch (\Throwable $e) {
                        $message = $e->getMessage();
                        if (str_contains($message, 'Maximum execution time') || str_contains($message, 'exceeded')) {
                            $this->recommendation = 'The request took too long (the model may be slow). Try again, or increase max_execution_time in php.ini.';
                        } else {
                            $this->recommendation = 'An error occurred: ' . $message;
                        }
                    } finally {
                        $this->loading = false;
                    }
                }),
        ];
    }

    /**
     * Persist a structured AI procurement run and its items so they can be
     * reviewed, edited, and approved later.
     */
    protected function storeAiProcurementRun(string $response): void
    {
        // Strip DeepSeek <think> blocks and normalise newlines.
        $clean = preg_replace('/<think>.*?<\/think>/s', '', $response);
        $clean = str_replace(["\r\n", "\r"], "\n", trim((string) $clean));

        if ($clean === '') {
            return;
        }

        $lines = explode("\n", $clean);

        // Summary: first non-empty line or short paragraph.
        $summaryLines = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                if (! empty($summaryLines)) {
                    break;
                }
                continue;
            }
            if (str_starts_with($t, '|')) {
                break;
            }
            $summaryLines[] = $t;
        }
        $summary = Str::limit(implode(' ', $summaryLines), 500);

        $run = AiProcurementRun::create([
            'ran_at'       => now(),
            'summary'      => $summary !== '' ? $summary : null,
            'raw_response' => $clean,
            'status'       => 'draft',
            'created_by'   => Auth::id(),
        ]);

        // Find first markdown table block (lines starting with | ... |).
        $inTable = false;
        $header = null;

        foreach ($lines as $line) {
            $t = trim($line);
            if (! $inTable) {
                if ($t !== '' && str_starts_with($t, '|') && str_contains($t, '|')) {
                    $inTable = true;
                    $header = $this->parseTableRow($t);
                }
                continue;
            }

            // Table separator or next row.
            if ($t === '' || ! str_starts_with($t, '|')) {
                break;
            }

            // Skip separator row like | --- | --- |
            if (preg_match('/^\|\s*-+/', $t)) {
                continue;
            }

            $cells = $this->parseTableRow($t);
            if ($header === null || empty($cells)) {
                continue;
            }

            $this->storeAiProcurementItemRow($run, $header, $cells);
        }
    }

    /**
     * Split a markdown table row into trimmed cell values.
     *
     * Example: "| A | B |" => ["A", "B"]
     */
    protected function parseTableRow(string $row): array
    {
        $parts = array_map('trim', explode('|', trim($row)));

        // Remove possible empty first/last elements caused by leading/trailing pipes.
        if ($parts && $parts[0] === '') {
            array_shift($parts);
        }
        if ($parts && end($parts) === '') {
            array_pop($parts);
        }

        return $parts;
    }

    /**
     * Map a parsed markdown table row into an AiProcurementItem record.
     */
    protected function storeAiProcurementItemRow(AiProcurementRun $run, array $header, array $cells): void
    {
        if (count($cells) !== count($header)) {
            return;
        }

        $data = array_combine($header, $cells);
        if (! $data) {
            return;
        }

        // Resolve cell by header: try exact key first, then case-insensitive match on normalized keys
        $get = function (array $keys) use ($data): ?string {
            foreach ($keys as $k) {
                if (isset($data[$k])) {
                    $v = $data[$k];
                    return $v === null ? null : trim((string) $v);
                }
            }
            foreach ($data as $header => $value) {
                $n = strtolower(preg_replace('/\s+/', ' ', trim($header)));
                foreach ($keys as $k) {
                    if (strtolower($k) === $n) {
                        return $value === null ? null : trim((string) $value);
                    }
                }
            }
            return null;
        };

        $priority      = $get(['Priority', 'priority']);
        $itemName      = $get(['Item', 'item']);
        $deptOrOffice  = $get(['Department/Office', 'Department / Office', 'department', 'Department', 'Office']);
        $currentStock  = $get(['Current stock', 'Stock', 'Current Stock', 'current stock']);
        $avgPerMonth   = $get(['Avg/month', 'Avg/mo', 'AVG/MO', 'Avg. monthly usage', 'Avg per month', 'Avg/month usage', 'Average per month']);
        $monthsCover   = $get(['Months of cover', 'Months cover', 'Cover', 'COVER', 'months of cover', 'Months of Cover']);
        $suggested     = $get(['Suggested reorder', 'Suggested qty', 'Suggested', 'Suggested reorder qty']);
        $reason        = $get(['Reason', 'Notes', 'reason', 'notes']);

        if (($itemName === null || trim($itemName) === '') && ($deptOrOffice === null || trim($deptOrOffice) === '')) {
            return;
        }

        [$minQty, $maxQty] = $this->parseSuggestedQuantity($suggested);

        // Try to resolve item and office IDs by name for better linking.
        $itemId = null;
        if ($itemName !== null && trim($itemName) !== '') {
            $itemId = Item::where('name', trim($itemName))
                ->orWhere('item_code', trim($itemName))
                ->value('id');
        }

        $officeId = null;
        if ($deptOrOffice !== null && trim($deptOrOffice) !== '') {
            $officeId = Office::where('name', trim($deptOrOffice))
                ->orWhere('code', trim($deptOrOffice))
                ->value('id');
        }

        AiProcurementItem::create([
            'run_id'             => $run->id,
            'section'            => 'urgent',
            'priority'           => $priority,
            'item_name'          => trim((string) $itemName),
            'item_id'            => $itemId,
            'office_name'        => $deptOrOffice !== null ? trim((string) $deptOrOffice) : null,
            'office_id'          => $officeId,
            'current_stock'      => is_numeric($currentStock) ? (int) $currentStock : null,
            'avg_monthly_usage'  => is_numeric($avgPerMonth) ? (float) $avgPerMonth : null,
            'months_cover'       => is_numeric($monthsCover) ? (float) $monthsCover : null,
            'suggested_qty_min'  => $minQty,
            'suggested_qty_max'  => $maxQty,
            'reason'             => $reason !== null ? trim((string) $reason) : null,
            'include_in_request' => true,
        ]);
    }

    /**
     * Parse suggested quantity cell, which may contain a single number
     * or a range like "80–100" or "80-100".
     *
     * @return array{0: int|null, 1: int|null}
     */
    protected function parseSuggestedQuantity(?string $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        preg_match_all('/\d+/', $value, $matches);
        $numbers = $matches[0] ?? [];

        if (count($numbers) === 1) {
            $n = (int) $numbers[0];
            return [$n, $n];
        }

        if (count($numbers) >= 2) {
            return [(int) $numbers[0], (int) $numbers[1]];
        }

        return [null, null];
    }

}
