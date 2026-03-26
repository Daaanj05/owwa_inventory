<?php

namespace App\Filament\Resources\Requisitions\Pages;

use App\Filament\Resources\Requisitions\RequisitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRequisitions extends ListRecords
{
    protected static string $resource = RequisitionResource::class;

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        $pending = \App\Models\Requisition::where('status', \App\Models\Requisition::STATUS_PENDING)->count();
        return $pending > 0
            ? "{$pending} pending " . \Illuminate\Support\Str::plural('requisition', $pending) . ' awaiting action.'
            : 'All requisitions are up to date.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
