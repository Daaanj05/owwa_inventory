<?php

use App\Http\Controllers\AiProcurementRunPrintController;
use App\Http\Controllers\AuditSessionController;
use App\Http\Controllers\CoaReportController;
use App\Http\Controllers\InventoryQrLabelController;
use App\Http\Controllers\OwwaBulkExportController;
use App\Http\Controllers\OwwaExportController;
use App\Http\Controllers\OwwaPrintController;
use App\Http\Controllers\PublicAssetController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/assets/{propertyNumber}', [PublicAssetController::class, 'show'])
    ->where('propertyNumber', '.+')
    ->middleware('throttle:60,1')
    ->name('inventory.assets.show');

Route::middleware(['auth', 'web'])->group(function () {
    Route::get('/email/verify', function () {
        return view('welcome');
    })->name('verification.notice');

    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        $user = $request->user();

        if ($user !== null && $user->isSystemAdmin()) {
            return redirect('/system-admin');
        }

        return redirect('/admin');
    })->middleware(['signed'])->name('verification.verify');

    Route::post('/email/verification-notification', function (Request $request) {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended('/admin');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    })->middleware(['throttle:6,1'])->name('verification.send');

    Route::post('audit/idle-logout', [AuditSessionController::class, 'idleLogout'])->name('audit.idle-logout');

    Route::get('reports/coa/stock-level', [CoaReportController::class, 'stockLevelReport'])->name('reports.coa.stock-level');
    Route::get('reports/coa/issuance', [CoaReportController::class, 'issuanceReport'])->name('reports.coa.issuance');
    Route::get('reports/coa/acquisition', [CoaReportController::class, 'acquisitionReport'])->name('reports.coa.acquisition');
    Route::get('reports/coa/transfer', [CoaReportController::class, 'transferReport'])->name('reports.coa.transfer');
    Route::get('reports/coa/disposal', [CoaReportController::class, 'disposalReport'])->name('reports.coa.disposal');
    Route::get('reports/owwa/acquisition/{acquisition}/qr-labels', [InventoryQrLabelController::class, 'acquisition'])->name('owwa.qr-labels.acquisition');
    Route::get('reports/owwa/acquisition/{acquisition}', [OwwaExportController::class, 'acquisition'])->name('owwa.export.acquisition');
    Route::get('reports/owwa/issuance/{issuance}', [OwwaExportController::class, 'issuance'])->name('owwa.export.issuance');
    Route::get('reports/owwa/transfer/{transfer}', [OwwaExportController::class, 'transfer'])->name('owwa.export.transfer');
    Route::get('reports/owwa/disposal/{disposal}', [OwwaExportController::class, 'disposal'])->name('owwa.export.disposal');
    Route::get('reports/owwa/requisition/{requisition}', [OwwaExportController::class, 'requisition'])->name('owwa.export.requisition');
    Route::get('reports/owwa/item/{item}', [OwwaExportController::class, 'item'])->name('owwa.export.item');
    Route::get('reports/owwa/physical-count/{physicalCountSession}', [OwwaExportController::class, 'physicalCount'])->name('owwa.export.physical-count');
    Route::get('reports/owwa/physical-count/{physicalCountSession}/qr-labels', [InventoryQrLabelController::class, 'physicalCountSession'])->name('owwa.qr-labels.physical-count');
    Route::get('reports/owwa/issuance/{issuance}/qr-label', [InventoryQrLabelController::class, 'issuance'])->name('owwa.qr-labels.issuance');
    Route::get('reports/owwa/acquisition-paperwork/{acquisitionPaperwork}/qr-labels', [InventoryQrLabelController::class, 'acquisitionPaperwork'])->name('owwa.qr-labels.acquisition-paperwork');
    Route::get('reports/owwa/acquisition-paperwork/{acquisitionPaperwork}/pr', [OwwaExportController::class, 'acquisitionPaperworkPr'])->name('owwa.export.acquisition-paperwork.pr');
    Route::get('reports/owwa/acquisition-paperwork/{acquisitionPaperwork}/po', [OwwaExportController::class, 'acquisitionPaperworkPo'])->name('owwa.export.acquisition-paperwork.po');
    Route::get('reports/owwa/acquisition-paperwork/{acquisitionPaperwork}/iar', [OwwaExportController::class, 'acquisitionPaperworkIar'])->name('owwa.export.acquisition-paperwork.iar');
    Route::get('reports/owwa/procurement/{acquisitionPaperwork}/pr', [OwwaExportController::class, 'procurementPr'])->name('owwa.export.procurement.pr');
    Route::get('reports/owwa/procurement/{acquisitionPaperwork}/po', [OwwaExportController::class, 'procurementPo'])->name('owwa.export.procurement.po');
    Route::get('reports/owwa/procurement/{acquisitionPaperwork}/iar', [OwwaExportController::class, 'procurementIar'])->name('owwa.export.procurement.iar');
    Route::get('reports/owwa/distribution/{distribution}', [OwwaExportController::class, 'distribution'])->name('owwa.export.distribution');
    Route::get('reports/owwa/bulk/acquisitions', [OwwaBulkExportController::class, 'acquisitions'])->name('owwa.export.bulk.acquisitions');
    Route::get('reports/owwa/bulk/annex-a1', [OwwaBulkExportController::class, 'annexA1'])->name('owwa.export.bulk.annex-a1');
    Route::get('reports/owwa/bulk/property-cards', [OwwaBulkExportController::class, 'propertyCards'])->name('owwa.export.bulk.property-cards');
    Route::get('reports/owwa/issuances/today-rsmi', [OwwaBulkExportController::class, 'issuancesTodayRsmi'])->name('owwa.export.issuances.today-rsmi');
    Route::get('reports/owwa/bulk/issuances', [OwwaBulkExportController::class, 'issuances'])->name('owwa.export.bulk.issuances');
    Route::get('reports/owwa/bulk/transfers', [OwwaBulkExportController::class, 'transfers'])->name('owwa.export.bulk.transfers');
    Route::get('reports/owwa/bulk/disposals', [OwwaBulkExportController::class, 'disposals'])->name('owwa.export.bulk.disposals');
    Route::get('reports/owwa/bulk/requisitions', [OwwaBulkExportController::class, 'requisitions'])->name('owwa.export.bulk.requisitions');
    Route::get('reports/owwa/issuance/{issuance}/print', [OwwaPrintController::class, 'issuance'])->name('owwa.print.issuance');
    Route::get('reports/owwa/transfer/{transfer}/print', [OwwaPrintController::class, 'transfer'])->name('owwa.print.transfer');
    Route::get('reports/owwa/disposal/{disposal}/print', [OwwaPrintController::class, 'disposal'])->name('owwa.print.disposal');
    Route::get('admin/ai-procurement-runs/{run}/print', AiProcurementRunPrintController::class)
        ->name('ai-procurement-runs.print');
});
