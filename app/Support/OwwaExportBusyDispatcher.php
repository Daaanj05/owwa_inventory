<?php

namespace App\Support;

use Livewire\Component as LivewireComponent;

final class OwwaExportBusyDispatcher
{
    public static function start(
        ?LivewireComponent $livewire,
        string $url,
        string $title = 'Preparing Excel export…',
        string $message = 'Building your workbook. Large selections can take a little while.',
        int $autoClearMs = 120000,
    ): void {
        if ($livewire === null) {
            return;
        }

        if (method_exists($livewire, 'startOwwaExportDownload')) {
            $livewire->startOwwaExportDownload($url, $title, $message, $autoClearMs);

            return;
        }

        if (str_contains($url, 'format=pdf') || str_contains($url, 'format%3Dpdf')) {
            OwwaLibreOfficeExportGuard::warnIfUnavailable();
        }

        if (method_exists($livewire, 'unmountAction')) {
            $livewire->unmountAction();
        }

        if (property_exists($livewire, 'exportBusy')) {
            $livewire->exportBusy = true;
        }

        $token = OwwaExportDownloadCookie::makeToken();
        $downloadUrl = OwwaExportDownloadCookie::sameOriginDownloadUrl($url, $token);

        $livewire->js(
            '(() => {'
            .'document.querySelectorAll(".fi-modal-close-overlay").forEach((el) => el.remove());'
            .'document.documentElement.classList.remove("fi-modal-open");'
            .'document.body.classList.remove("fi-modal-open");'
            .'document.body.style.removeProperty("overflow");'
            .'window.dispatchEvent(new CustomEvent("owwa-busy-start", { detail: '
            .json_encode([
                'title' => $title,
                'message' => $message,
                'token' => $token,
                'url' => $downloadUrl,
                'autoClearMs' => $autoClearMs,
            ], JSON_UNESCAPED_SLASHES)
            .'}));'
            .'})();'
        );
    }
}
