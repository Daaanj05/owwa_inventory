<?php

namespace App\Filament\Concerns;

use App\Support\OwwaExportDownloadCookie;

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
        // Close any open Filament modal first so it cannot leave a blank dark shell
        // while the browser prepares the file download.
        if (method_exists($this, 'unmountAction')) {
            $this->unmountAction();
        }

        $this->exportBusy = true;

        $token = OwwaExportDownloadCookie::makeToken();
        $downloadUrl = OwwaExportDownloadCookie::sameOriginDownloadUrl($url, $token);

        // Overlay only — navigation is handled by Livewire redirect below.
        $this->js(
            'window.dispatchEvent(new CustomEvent("owwa-busy-start", { detail: '
            .json_encode([
                'title' => $title,
                'message' => $message,
                'token' => $token,
                'autoClearMs' => $autoClearMs,
            ], JSON_UNESCAPED_SLASHES)
            .'}));'
        );

        // Livewire's redirect effect is the reliable download trigger. The previous
        // CustomEvent → location.assign chain often never navigated the browser.
        $this->redirect($downloadUrl);
    }
}
