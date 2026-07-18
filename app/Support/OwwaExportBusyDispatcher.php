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

        if (method_exists($livewire, 'unmountAction')) {
            $livewire->unmountAction();
        }

        if (property_exists($livewire, 'exportBusy')) {
            $livewire->exportBusy = true;
        }

        $token = OwwaExportDownloadCookie::makeToken();
        $downloadUrl = OwwaExportDownloadCookie::sameOriginDownloadUrl($url, $token);

        $livewire->js(
            'window.dispatchEvent(new CustomEvent("owwa-busy-start", { detail: '
            .json_encode([
                'title' => $title,
                'message' => $message,
                'token' => $token,
                'autoClearMs' => $autoClearMs,
            ], JSON_UNESCAPED_SLASHES)
            .'}));'
        );

        $livewire->redirect($downloadUrl);
    }
}
