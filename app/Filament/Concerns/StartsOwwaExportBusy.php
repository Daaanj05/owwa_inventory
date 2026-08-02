<?php

namespace App\Filament\Concerns;

use App\Support\OwwaExportDiagnostics;
use App\Support\OwwaExportDownloadCookie;
use App\Support\OwwaLibreOfficeExportGuard;

trait StartsOwwaExportBusy
{
    public bool $exportBusy = false;

    public function clearExportBusy(): void
    {
        $this->exportBusy = false;
    }

    public function startOwwaExportDownload(
        string $url,
        string $title = 'Preparing export…',
        string $message = 'Building your file. Large exports can take a little while.',
        int $autoClearMs = 120000,
    ): void {
        OwwaExportDiagnostics::raiseMemoryLimit('512M');

        if (str_contains($url, 'format=pdf') || str_contains($url, 'format%3Dpdf')) {
            OwwaLibreOfficeExportGuard::warnIfUnavailable();
        }

        // Close any open Filament modal first so it cannot leave a blank dark shell
        // while the browser prepares the file download.
        if (method_exists($this, 'unmountAction')) {
            $this->unmountAction();
        }

        $this->exportBusy = true;

        $token = OwwaExportDownloadCookie::makeToken();
        $downloadUrl = OwwaExportDownloadCookie::sameOriginDownloadUrl($url, $token);

        OwwaExportDiagnostics::info('dispatching_client_download', [
            'livewire_class' => static::class,
            'url' => $url,
            'download_url' => $downloadUrl,
            'title' => $title,
        ]);

        // Clear leftover Filament modal backdrop, show busy overlay, then navigate.
        // Livewire redirect alone can race Alpine and leave a blank dark shell.
        $this->js(
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
