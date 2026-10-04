<?php

namespace App\Http\Controllers;

use App\Services\StockCardQueuedExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockCardExportDownloadController extends Controller
{
    public function __invoke(Request $request, int $user, string $file): StreamedResponse
    {
        abort_unless($request->user()?->id === $user, 403);

        $basename = basename($file);
        abort_unless($basename === $file && preg_match('/^[A-Za-z0-9._-]+$/', $basename) === 1, 404);

        $path = StockCardQueuedExportService::DIRECTORY.'/'.$user.'/'.$basename;
        $disk = Storage::disk(StockCardQueuedExportService::DISK);
        abort_unless($disk->exists($path), 404);

        $downloadName = preg_replace('/^[0-9a-f-]{36}-/i', '', $basename) ?: $basename;
        $mime = match (true) {
            str_ends_with(strtolower($basename), '.pdf') => 'application/pdf',
            str_ends_with(strtolower($basename), '.html') => 'text/html; charset=UTF-8',
            str_ends_with(strtolower($basename), '.zip') => 'application/zip',
            default => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };

        $inline = $request->boolean('inline');
        $disposition = ($inline ? 'inline' : 'attachment').'; filename="'.$downloadName.'"';

        return response()->streamDownload(function () use ($disk, $path): void {
            echo $disk->get($path);
        }, $downloadName, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition,
        ], $inline ? 'inline' : 'attachment');
    }
}
