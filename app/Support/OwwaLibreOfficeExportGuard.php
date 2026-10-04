<?php

namespace App\Support;

use App\Services\LibreOfficePdfConverter;
use Filament\Notifications\Notification;

class OwwaLibreOfficeExportGuard
{
    /**
     * True when this download URL still goes through LibreOffice template→PDF conversion.
     * Coded DomPDF lookalikes (PR/PO/IAR Fast, stock-card Fast) must not probe soffice.
     */
    public static function urlNeedsLibreOffice(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');

        // DomPDF lookalike routes (path-based).
        if (
            str_contains($path, '/bulk/procurement')
            || str_contains($path, '/pr-fast-pdf')
            || str_contains($path, '/rsmi-fast-pdf')
            || str_contains($path, '/bulk/issuances/rsmi')
            || str_contains($path, '/stock-cards-fast')
            || str_contains($path, '/purchase-orders/') && str_ends_with($path, '/pdf')
            || str_contains($path, '/inspection-acceptance-reports/') && str_ends_with($path, '/pdf')
        ) {
            return false;
        }

        return str_contains($query, 'format=pdf')
            || str_contains($url, 'format%3Dpdf')
            || str_contains($url, 'format=pdf');
    }

    public static function warnIfUnavailable(): void
    {
        $converter = app(LibreOfficePdfConverter::class);

        if ($converter->isAvailable()) {
            return;
        }

        $binary = $converter->binary();

        Notification::make()
            ->title('LibreOffice not available')
            ->body(LibreOfficePdfConverter::unavailableMessage($binary))
            ->warning()
            ->persistent()
            ->send();
    }
}
