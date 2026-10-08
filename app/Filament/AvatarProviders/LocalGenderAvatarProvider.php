<?php

namespace App\Filament\AvatarProviders;

use App\Models\User;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

class LocalGenderAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        return asset('images/'.$this->filename($record));
    }

    public function filename(Model $record): string
    {
        $gender = $record instanceof User ? $record->gender : null;

        return match ($gender) {
            User::GENDER_MALE => 'avatar-male.svg',
            User::GENDER_FEMALE => 'avatar-female.svg',
            default => 'avatar-neutral.svg',
        };
    }
}
