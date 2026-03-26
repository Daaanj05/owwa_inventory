<?php

namespace App\Http\Controllers;

use App\Models\AiProcurementRun;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AiProcurementRunPrintController extends Controller
{
    public function __invoke(AiProcurementRun $run)
    {
        $user = Auth::user();
        if (! $user || $user->role !== User::ROLE_SUPPLY_CUSTODIAN) {
            abort(403, 'Only the Supply Custodian may print AI procurement runs.');
        }

        $run->load('items');

        return view('filament.ai.print-procurement-run', [
            'run' => $run,
            'items' => $run->items()->orderBy('priority')->get(),
        ]);
    }
}

