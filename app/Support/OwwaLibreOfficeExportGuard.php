<?php

namespace App\Support;

use App\Services\LibreOfficePdfConverter;
use Filament\Notifications\Notification;

class OwwaLibreOfficeExportGuard
{
    public static function warnIfUnavailable(): void
    {
        $converter = app(LibreOfficePdfConverter::class);

        if ($converter->isAvailable()) {
            return;
        }

        $binary = $converter->binary();

        Notification::make()
            ->title('LibreOffice not available')
            ->body(
                'Excel-like PDF needs LibreOffice. Install it and set LIBREOFFICE_BINARY '
                ."(current: {$binary}). Export will use a simplified Dompdf fallback until then."
            )
            ->warning()
            ->persistent()
            ->send();
    }
}
