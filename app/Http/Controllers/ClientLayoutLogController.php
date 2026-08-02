<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ClientLayoutLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'url' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:100'],
            'viewport' => ['nullable', 'array'],
            'hasValidationError' => ['nullable', 'boolean'],
            'deadSpacePx' => ['nullable', 'numeric'],
            'signals' => ['nullable', 'array'],
            'contentChildrenSum' => ['nullable', 'numeric'],
            'windowChildrenSum' => ['nullable', 'numeric'],
            'modalOpen' => ['nullable', 'boolean'],
            'ctn' => ['nullable', 'array'],
            'window' => ['nullable', 'array'],
            'header' => ['nullable', 'array'],
            'content' => ['nullable', 'array'],
            'footer' => ['nullable', 'array'],
        ]);

        Log::warning('owwa-modal-layout dead space', [
            'user_id' => $request->user()?->id,
            'reason' => $payload['reason'] ?? null,
            'report' => $payload,
        ]);

        return response()->json(['ok' => true]);
    }
}
