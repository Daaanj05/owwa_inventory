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
        $this->startOwwaExportDownloads(
            urls: [$url],
            title: $title,
            message: $message,
            autoClearMs: $autoClearMs,
        );
    }

    /**
     * @param  array<int, string>  $urls
     */
    public function startOwwaExportDownloads(
        array $urls,
        string $title = 'Preparing export…',
        string $message = 'Building your file. Large exports can take a little while.',
        int $autoClearMs = 120000,
    ): void {
        $urls = array_values(array_filter($urls, fn (mixed $url): bool => is_string($url) && filled($url)));

        if ($urls === []) {
            return;
        }

        OwwaExportDiagnostics::raiseMemoryLimit('2048M');

        $needsLibreOffice = collect($urls)->contains(
            fn (string $url): bool => OwwaLibreOfficeExportGuard::urlNeedsLibreOffice($url),
        );

        if ($needsLibreOffice) {
            OwwaLibreOfficeExportGuard::warnIfUnavailable();
        }

        // Close any open Filament modal first so it cannot leave a blank dark shell
        // while the browser prepares the file download.
        if (method_exists($this, 'unmountAction')) {
            $this->unmountAction();
        }

        $this->exportBusy = true;

        $downloadUrls = array_map(
            fn (string $url): string => OwwaExportDownloadCookie::sameOriginDownloadUrl($url),
            $urls,
        );

        OwwaExportDiagnostics::info('dispatching_client_download', [
            'livewire_class' => static::class,
            'url' => $urls[0],
            'download_url' => $downloadUrls[0],
            'batch_count' => count($downloadUrls),
            'title' => $title,
        ]);

        $detail = [
            'title' => $title,
            'message' => $message,
            'autoClearMs' => $autoClearMs,
        ];

        if (count($downloadUrls) === 1) {
            $detail['url'] = $downloadUrls[0];
        } else {
            $detail['urls'] = $downloadUrls;
        }

        // Clear leftover Filament modal backdrop, show busy overlay, then navigate.
        // Livewire redirect alone can race Alpine and leave a blank dark shell.
        $this->js(
            '(() => {'
            .'document.querySelectorAll(".fi-modal-close-overlay").forEach((el) => el.remove());'
            .'document.documentElement.classList.remove("fi-modal-open");'
            .'document.body.classList.remove("fi-modal-open");'
            .'document.body.style.removeProperty("overflow");'
            .'window.dispatchEvent(new CustomEvent("owwa-busy-start", { detail: '
            .json_encode($detail, JSON_UNESCAPED_SLASHES)
            .'}));'
            .'})();'
        );
    }
}
