<?php

namespace App\Http\Concerns;

use App\Filament\Pages\CoaReports;
use App\Filament\Pages\StockLevels;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\InspectionAcceptanceReportResource;
use App\Filament\Resources\Acquisitions\Paperwork\AcquisitionPaperworkResource;
use App\Filament\Resources\Acquisitions\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\Distributions\DistributionResource;
use App\Filament\Resources\Issuances\IssuanceResource;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\Disposal;
use App\Models\Distribution;
use App\Models\InspectionAcceptanceReport;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\PhysicalCountSession;
use App\Models\PurchaseOrder;
use App\Models\Transfer;
use App\Models\User;
use App\Support\CustodianOfficeScope;
use Illuminate\Support\Facades\Auth;

trait AuthorizesOwwaExports
{
    protected function authorizeAcquisitionExport(Acquisition $acquisition): void
    {
        abort_unless(AcquisitionResource::canViewAny(), 403);
        $this->assertCustodianOffice((int) $acquisition->office_id);
    }

    protected function authorizeIssuanceExport(Issuance $issuance): void
    {
        abort_unless(IssuanceResource::canViewAny(), 403);
        $this->assertCustodianOffice((int) $issuance->office_id);
    }

    protected function authorizeTransferExport(Transfer $transfer): void
    {
        abort_unless(TransferResource::canViewAny(), 403);

        $fixedOfficeId = CustodianOfficeScope::inventoryOfficeId();
        if ($fixedOfficeId === null) {
            return;
        }

        abort_unless(
            (int) $transfer->from_office_id === $fixedOfficeId
            || (int) $transfer->to_office_id === $fixedOfficeId,
            403,
        );
    }

    protected function authorizeDisposalExport(Disposal $disposal): void
    {
        abort_unless(DisposalResource::canViewAny(), 403);
        $this->assertCustodianOffice((int) $disposal->office_id);
    }

    protected function authorizeItemExport(Item $item): void
    {
        abort_unless(ItemResource::canViewAny(), 403);
    }

    protected function authorizePhysicalCountExport(PhysicalCountSession $session): void
    {
        abort_unless(PhysicalCountSessionResource::canViewAny(), 403);
        $this->assertCustodianOffice((int) $session->office_id);
    }

    protected function authorizeDistributionExport(Distribution $distribution): void
    {
        abort_unless(DistributionResource::canViewAny(), 403);

        $user = Auth::user();
        abort_unless($user instanceof User && $user->isUnitConsolidator(), 403);

        if ($user->office_id) {
            abort_unless((int) $distribution->office_id === (int) $user->office_id, 403);
        }

        if ($user->department_id) {
            abort_unless((int) $distribution->department_id === (int) $user->department_id, 403);
        }
    }

    protected function authorizeAcquisitionPaperworkExport(AcquisitionPaperwork $paperwork): void
    {
        abort_unless(AcquisitionPaperworkResource::canViewAny(), 403);
        $this->assertCustodianOffice((int) $paperwork->office_id);
    }

    protected function authorizePurchaseOrderExport(PurchaseOrder $purchaseOrder): void
    {
        abort_unless(PurchaseOrderResource::canViewAny(), 403);

        $purchaseOrder->loadMissing('purchaseRequest');
        $officeId = $purchaseOrder->purchaseRequest?->office_id;

        if ($officeId !== null) {
            $this->assertCustodianOffice((int) $officeId);
        }
    }

    protected function authorizeInspectionAcceptanceReportExport(InspectionAcceptanceReport $iar): void
    {
        abort_unless(InspectionAcceptanceReportResource::canViewAny(), 403);

        $iar->loadMissing('purchaseOrder.purchaseRequest');
        $officeId = $iar->purchaseOrder?->purchaseRequest?->office_id;

        if ($officeId !== null) {
            $this->assertCustodianOffice((int) $officeId);
        }
    }

    protected function authorizeCoaReportAccess(): void
    {
        abort_unless(CoaReports::canAccess() || StockLevels::canAccess(), 403);
    }

    protected function assertCustodianOffice(int $officeId): void
    {
        $fixedOfficeId = CustodianOfficeScope::inventoryOfficeId();

        if ($fixedOfficeId !== null) {
            abort_unless($officeId === $fixedOfficeId, 403);
        }
    }
}
