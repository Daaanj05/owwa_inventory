<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        $total   = \App\Models\User::count();
        $custodians = \App\Models\User::where('role', \App\Models\User::ROLE_SUPPLY_CUSTODIAN)->count();
        return $total > 0
            ? "{$total} " . \Illuminate\Support\Str::plural('user', $total) . ", {$custodians} Supply " . \Illuminate\Support\Str::plural('Custodian', $custodians) . '.'
            : 'No users yet.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
