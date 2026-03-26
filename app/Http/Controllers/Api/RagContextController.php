<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RagContextService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RAG (Retrieval-Augmented Generation) context endpoint.
 *
 * Returns structured inventory data so an LLM (e.g. Ollama/DeepSeek) can answer
 * questions using real numbers. Use this payload as context when calling the AI.
 *
 * For production, protect with auth (e.g. API token or Sanctum).
 */
class RagContextController extends Controller
{
    public function __invoke(Request $request, RagContextService $rag): JsonResponse
    {
        $from = $request->has('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : null;
        $to = $request->has('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : null;
        $maxMonths = $request->integer('max_months', 60); // default up to 5 years

        $context = $rag->buildContext($from, $to, $maxMonths > 0 ? $maxMonths : null);

        return response()->json($context);
    }
}
