<?php

namespace App\Http\Controllers;

use App\Services\StockCardQueuedExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class StockCardExportPrintController extends Controller
{
    public function __invoke(Request $request, int $user, string $file): View
    {
        abort_unless($request->user()?->id === $user, 403);

        $basename = basename($file);
        abort_unless($basename === $file && preg_match('/^[A-Za-z0-9._-]+$/', $basename) === 1, 404);

        $path = StockCardQueuedExportService::DIRECTORY.'/'.$user.'/'.$basename;
        $disk = Storage::disk(StockCardQueuedExportService::DISK);
        abort_unless($disk->exists($path), 404);

        $lower = strtolower($basename);
        $mode = str_ends_with($lower, '.html') ? 'html' : 'pdf';
        abort_unless(in_array($mode, ['html', 'pdf'], true) && (str_ends_with($lower, '.html') || str_ends_with($lower, '.pdf')), 404);

        $downloadFile = $basename;
        if ($mode === 'html') {
            $pdfCandidate = preg_replace('/\.html$/i', '.pdf', $basename) ?? $basename;
            if ($disk->exists(StockCardQueuedExportService::DIRECTORY.'/'.$user.'/'.$pdfCandidate)) {
                $downloadFile = $pdfCandidate;
            }
        }

        $downloadUrl = URL::temporarySignedRoute(
            'owwa.export.stock-cards.download',
            now()->addDay(),
            [
                'user' => $user,
                'file' => $downloadFile,
            ],
        );

        $inlineUrl = URL::temporarySignedRoute(
            'owwa.export.stock-cards.download',
            now()->addDay(),
            [
                'user' => $user,
                'file' => $basename,
                'inline' => 1,
            ],
        );

        $title = 'Stock Card — Print view';

        return view('owwa.stock-card-export-print', [
            'title' => $title,
            'mode' => $mode,
            'downloadUrl' => $downloadUrl,
            'inlineUrl' => $inlineUrl,
            'filename' => preg_replace('/^[0-9a-f-]{36}-/i', '', $downloadFile) ?: $downloadFile,
        ]);
    }
}
