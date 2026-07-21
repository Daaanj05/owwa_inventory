<?php

namespace App\Support;

class OwwaFilamentTheme
{
    public static function stylesheetLinkTag(): string
    {
        $relativePath = 'css/filament/admin/owwa-theme.css';
        $absolutePath = public_path($relativePath);
        $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : null;
        $href = asset($relativePath).($version !== null ? '?v='.$version : '');

        return '<link rel="stylesheet" href="'.e($href).'">';
    }
}
