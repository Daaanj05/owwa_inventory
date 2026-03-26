<?php

use App\Http\Controllers\AiProcurementRunPrintController;
use App\Http\Controllers\CoaReportController;
use App\Http\Controllers\OwwaExportController;
use App\Http\Controllers\OwwaPrintController;
use App\Services\FiscalYearService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'web'])->group(function () {
    Route::get('reports/coa/stock-level', [CoaReportController::class, 'stockLevelReport'])->name('reports.coa.stock-level');
    Route::get('reports/coa/issuance', [CoaReportController::class, 'issuanceReport'])->name('reports.coa.issuance');
    Route::get('reports/owwa/issuance/{issuance}', [OwwaExportController::class, 'issuance'])->name('owwa.export.issuance');
    Route::get('reports/owwa/transfer/{transfer}', [OwwaExportController::class, 'transfer'])->name('owwa.export.transfer');
    Route::get('reports/owwa/disposal/{disposal}', [OwwaExportController::class, 'disposal'])->name('owwa.export.disposal');
    Route::get('reports/owwa/requisition/{requisition}', [OwwaExportController::class, 'requisition'])->name('owwa.export.requisition');
    Route::get('reports/owwa/issuance/{issuance}/print', [OwwaPrintController::class, 'issuance'])->name('owwa.print.issuance');
    Route::get('reports/owwa/transfer/{transfer}/print', [OwwaPrintController::class, 'transfer'])->name('owwa.print.transfer');
    Route::get('reports/owwa/disposal/{disposal}/print', [OwwaPrintController::class, 'disposal'])->name('owwa.print.disposal');
    Route::get('admin/ai-procurement-runs/{run}/print', AiProcurementRunPrintController::class)
        ->name('ai-procurement-runs.print');
    Route::post('admin/fiscal-year/set', function (Request $request) {
        $request->validate(['fiscal_year_id' => ['required', 'integer', 'exists:fiscal_years,id']]);
        app(FiscalYearService::class)->setCurrent((int) $request->fiscal_year_id);
        return redirect()->back();
    })->name('filament.admin.fiscal-year.set');
    Route::get('admin/fiscal-year/change', function () {
        app(FiscalYearService::class)->setCurrent(null);
        return redirect()->back();
    })->name('filament.admin.fiscal-year.change');
});
