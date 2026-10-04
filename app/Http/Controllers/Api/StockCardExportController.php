<?php

namespace App\Http\Controllers\Api;

use App\Filament\Pages\StockLevels;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateStockCardExportJob;
use App\Models\ItemCategory;
use App\Models\User;
use App\Services\StockCardExportStatusService;
use App\Services\StockLevelExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\Rule;

class StockCardExportController extends Controller
{
    public function store(Request $request, StockCardExportStatusService $statusService): JsonResponse
    {
        abort_unless(StockLevels::canAccess(), 403);

        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'format' => ['required', Rule::in(['xlsx', 'pdf'])],
            'scope' => ['required', Rule::in(['all', 'selected'])],
            'download_size' => ['nullable', Rule::in(['one', 'batches'])],
            'category_id' => ['nullable', 'integer', 'exists:item_categories,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'restock_filter' => ['nullable', Rule::in(['active', 'inactive'])],
            'selected_keys' => ['nullable', 'array'],
            'selected_keys.*' => ['string', 'max:64'],
            'notify_database' => ['nullable', 'boolean'],
        ]);

        $scope = (string) $validated['scope'];
        $selectedKeys = array_values($validated['selected_keys'] ?? []);

        if ($scope === 'selected' && $selectedKeys === []) {
            return response()->json([
                'message' => 'Select at least one stock position, or use scope=all.',
            ], 422);
        }

        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $search = filled($validated['search'] ?? null) ? (string) $validated['search'] : null;
        $restockFilter = (string) ($validated['restock_filter'] ?? 'active');
        $scopedOfficeId = $user->office_id ? (int) $user->office_id : null;
        $format = (string) $validated['format'];

        $exportService = app(StockLevelExportService::class);
        $count = $exportService->countPairs(
            categoryId: $categoryId,
            search: $search,
            restockFilter: $restockFilter,
            scopedOfficeId: $scopedOfficeId,
            explicitPairKeys: $scope === 'selected' ? $selectedKeys : [],
        );

        if ($count === 0) {
            return response()->json([
                'message' => 'No stock positions matched the current filters.',
            ], 422);
        }

        $downloadSize = (string) ($validated['download_size'] ?? '');
        if ($count > StockLevelExportService::BATCH_SIZE && $downloadSize === '') {
            $downloadSize = $count > StockLevelExportService::SINGLE_MAX ? 'batches' : 'one';
        }
        if ($count <= StockLevelExportService::BATCH_SIZE) {
            $downloadSize = 'one';
        }

        if ($downloadSize === 'one' && $count > StockLevelExportService::SINGLE_MAX) {
            return response()->json([
                'message' => 'One file supports at most '.StockLevelExportService::SINGLE_MAX.' positions. Use download_size=batches.',
            ], 422);
        }

        $category = $categoryId !== null
            ? ItemCategory::query()->find($categoryId)
            : null;

        // Mark queued before push: with QUEUE_CONNECTION=sync the job finishes (ready)
        // inside push(), and a later markQueued would wipe that ready status.
        $statusService->markQueued((int) $user->id, $format);

        Queue::push(new GenerateStockCardExportJob(
            userId: (int) $user->id,
            categorySlug: $category?->getTemplateSlug() ?? 'consumables',
            format: $format,
            downloadSize: $downloadSize,
            scope: $scope,
            categoryId: $categoryId,
            search: $search,
            restockFilter: $restockFilter,
            scopedOfficeId: $scopedOfficeId,
            selectedKeys: $scope === 'selected' ? $selectedKeys : [],
            notifyDatabase: (bool) ($validated['notify_database'] ?? true),
        ));

        return response()->json([
            'status' => 'queued',
            'export_count' => $count,
            'format' => $format,
            'download_size' => $downloadSize,
            'message' => 'Export started in the background. Poll GET /api/stock-card-exports/status for readiness.',
        ], 202);
    }

    public function status(Request $request, StockCardExportStatusService $statusService): JsonResponse
    {
        abort_unless(StockLevels::canAccess(), 403);

        /** @var User $user */
        $user = $request->user();
        $status = $statusService->statusFor((int) $user->id);

        if ($status === null) {
            return response()->json([
                'status' => 'idle',
                'format' => null,
                'filename' => null,
                'download_url' => null,
                'preview_url' => null,
            ]);
        }

        return response()->json($status);
    }
}
