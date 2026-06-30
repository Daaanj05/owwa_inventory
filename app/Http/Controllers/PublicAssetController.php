<?php

namespace App\Http\Controllers;

use App\Services\InventoryUnitPublicLookupService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PublicAssetController extends Controller
{
    public function show(string $propertyNumber, InventoryUnitPublicLookupService $lookup): View|SymfonyResponse
    {
        if (! config('inventory.qr_public_lookup', true)) {
            abort(SymfonyResponse::HTTP_NOT_FOUND);
        }

        $propertyNumber = rawurldecode($propertyNumber);
        $asset = $lookup->findByPropertyNumber($propertyNumber);

        if ($asset === null) {
            abort(SymfonyResponse::HTTP_NOT_FOUND, 'Asset not found.');
        }

        return view('public.asset-card', [
            'asset' => $asset,
        ]);
    }
}
