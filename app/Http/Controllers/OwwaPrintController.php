<?php

namespace App\Http\Controllers;

use App\Models\Acquisition;
use App\Models\Disposal;
use App\Models\Issuance;
use App\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OwwaPrintController extends Controller
{
    public function issuance(Issuance $issuance, Request $request): View
    {
        $issuance->load(['item', 'item.category', 'office', 'department', 'issuedBy', 'issuedTo']);

        $dateAcquired = $this->lookupDateAcquired($issuance->item_id);
        $form = $request->query('form');

        if ($form === 'par') {
            return view('owwa.par-print', [
                'issuance' => $issuance,
                'dateAcquired' => $dateAcquired,
                'title' => 'Property Acknowledgment Receipt (Appendix 71 - PAR)',
            ]);
        }
        if ($form === 'ics') {
            return view('owwa.ics-print', [
                'issuance' => $issuance,
                'dateAcquired' => $dateAcquired,
                'title' => 'Inventory Custodian Slip (Appendix 59 - ICS)',
            ]);
        }

        return view('owwa.rsmi-print', [
            'issuance' => $issuance,
            'title' => 'Report of Supplies and Materials Issued (Appendix 64 - RSMI)',
        ]);
    }

    public function transfer(Transfer $transfer): View
    {
        $transfer->load(['item', 'fromOffice', 'toOffice', 'recordedBy']);

        $dateAcquired = $this->lookupDateAcquired($transfer->item_id);
        $acquisitionCost = $this->lookupAcquisitionCost($transfer->item_id, $transfer->quantity);

        return view('owwa.ptr-print', [
            'transfer' => $transfer,
            'dateAcquired' => $dateAcquired,
            'acquisitionCost' => $acquisitionCost,
            'title' => 'Property Transfer Report (Appendix 76 - PTR)',
        ]);
    }

    public function disposal(Disposal $disposal, Request $request): View
    {
        $disposal->load(['item', 'item.category', 'office']);

        $dateAcquired = $this->lookupDateAcquired($disposal->item_id);
        $form = $request->query('form');

        if ($form === 'iirup' || $form === 'iirusp') {
            return view('owwa.iirup-print', [
                'disposal' => $disposal,
                'dateAcquired' => $dateAcquired,
                'title' => $form === 'iirusp'
                    ? 'Inventory and Inspection Report of Unserviceable Semi-Expendable Property (Annex A.10 - IIRUSP)'
                    : 'Inventory and Inspection Report of Unserviceable Property (Appendix 74 - IIRUP)',
            ]);
        }
        if ($form === 'rlsddp') {
            return view('owwa.rlsddp-print', [
                'disposal' => $disposal,
                'dateAcquired' => $dateAcquired,
                'title' => 'Report of Lost, Stolen, Damaged or Destroyed Property (Appendix 75 - RLSDDP)',
            ]);
        }

        return view('owwa.wmr-print', [
            'disposal' => $disposal,
            'title' => 'Waste Materials Report (Appendix 65 - WMR)',
        ]);
    }

    /**
     * Look up the most recent acquisition date for an item.
     */
    private function lookupDateAcquired(?int $itemId): ?string
    {
        if ($itemId === null) {
            return null;
        }

        $date = Acquisition::query()
            ->where('item_id', $itemId)
            ->orderByDesc('acquisition_date')
            ->value('acquisition_date');

        return $date?->format('Y-m-d');
    }

    /**
     * Look up acquisition cost (unit_cost × quantity) from the most recent acquisition.
     */
    private function lookupAcquisitionCost(?int $itemId, ?int $quantity): ?float
    {
        if ($itemId === null) {
            return null;
        }

        $unitCost = Acquisition::query()
            ->where('item_id', $itemId)
            ->orderByDesc('acquisition_date')
            ->value('unit_cost');

        if ($unitCost === null) {
            return null;
        }

        return (float) $unitCost * ($quantity ?? 1);
    }
}
