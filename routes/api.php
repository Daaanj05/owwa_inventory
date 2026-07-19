<?php

use App\Http\Controllers\Api\RagContextController;
use App\Http\Middleware\EnsureRagContextToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RAG context for AI (Ollama/DeepSeek)
|--------------------------------------------------------------------------
|
| GET /api/rag/context
| Requires Authorization: Bearer {RAG_CONTEXT_TOKEN}.
| Optional query: from (date), to (date), max_months (e.g. 60 for 5 years).
| Returns JSON with data_range, summary, consumption_by_department, low_stock_summary.
| Use this as context when calling your LLM.
|
*/

Route::get('/rag/context', RagContextController::class)
    ->middleware([EnsureRagContextToken::class, 'throttle:30,1'])
    ->name('rag.context');
