<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Concatenate PDF binaries into one file (preserves LibreOffice page fidelity).
 */
final class OwwaPdfBinaryMerger
{
    /**
     * @param  list<string>  $pdfBinaries
     */
    public static function merge(array $pdfBinaries): string
    {
        $pdfBinaries = array_values(array_filter(
            $pdfBinaries,
            fn (mixed $binary): bool => is_string($binary) && str_starts_with($binary, '%PDF'),
        ));

        if ($pdfBinaries === []) {
            throw new RuntimeException('No PDF binaries to merge.');
        }

        if (count($pdfBinaries) === 1) {
            return $pdfBinaries[0];
        }

        $merged = self::mergeWithGhostscript($pdfBinaries);
        if ($merged !== null) {
            return $merged;
        }

        $merged = self::mergeWithQpdf($pdfBinaries);
        if ($merged !== null) {
            return $merged;
        }

        // Last resort: keep LibreOffice fidelity by returning the first document only
        // is unacceptable — use a lightweight page-import merge when possible.
        $merged = self::mergeWithPhpFpdiCompat($pdfBinaries);
        if ($merged !== null) {
            return $merged;
        }

        throw new RuntimeException(
            'Unable to merge PDFs. Install Ghostscript (gswin64c) or qpdf for bulk PDF export.',
        );
    }

    /**
     * @param  list<string>  $pdfBinaries
     */
    protected static function mergeWithGhostscript(array $pdfBinaries): ?string
    {
        $binary = self::firstAvailableBinary(['gswin64c', 'gswin32c', 'gs']);
        if ($binary === null) {
            return null;
        }

        $workDir = storage_path('app/tmp/pdfmerge_'.Str::lower((string) Str::uuid()));

        try {
            if (! is_dir($workDir) && ! mkdir($workDir, 0775, true) && ! is_dir($workDir)) {
                return null;
            }

            $inputs = [];
            foreach ($pdfBinaries as $index => $pdf) {
                $path = $workDir.DIRECTORY_SEPARATOR.sprintf('in_%03d.pdf', $index);
                if (file_put_contents($path, $pdf) === false) {
                    return null;
                }
                $inputs[] = $path;
            }

            $outputPath = $workDir.DIRECTORY_SEPARATOR.'merged.pdf';
            $result = Process::timeout(300)->run(array_merge(
                [$binary, '-dBATCH', '-dNOPAUSE', '-dSAFER', '-q', '-sDEVICE=pdfwrite', '-sOutputFile='.$outputPath],
                $inputs,
            ));

            if (! $result->successful() || ! is_file($outputPath)) {
                return null;
            }

            $merged = file_get_contents($outputPath);

            return is_string($merged) && str_starts_with($merged, '%PDF') ? $merged : null;
        } catch (Throwable) {
            return null;
        } finally {
            self::deleteDirectory($workDir);
        }
    }

    /**
     * @param  list<string>  $pdfBinaries
     */
    protected static function mergeWithQpdf(array $pdfBinaries): ?string
    {
        $binary = self::firstAvailableBinary(['qpdf']);
        if ($binary === null) {
            return null;
        }

        $workDir = storage_path('app/tmp/pdfmerge_'.Str::lower((string) Str::uuid()));

        try {
            if (! is_dir($workDir) && ! mkdir($workDir, 0775, true) && ! is_dir($workDir)) {
                return null;
            }

            $inputs = [];
            foreach ($pdfBinaries as $index => $pdf) {
                $path = $workDir.DIRECTORY_SEPARATOR.sprintf('in_%03d.pdf', $index);
                if (file_put_contents($path, $pdf) === false) {
                    return null;
                }
                $inputs[] = $path;
            }

            $outputPath = $workDir.DIRECTORY_SEPARATOR.'merged.pdf';
            $result = Process::timeout(300)->run(array_merge(
                [$binary, '--empty', '--pages'],
                $inputs,
                ['--', $outputPath],
            ));

            if (! $result->successful() || ! is_file($outputPath)) {
                return null;
            }

            $merged = file_get_contents($outputPath);

            return is_string($merged) && str_starts_with($merged, '%PDF') ? $merged : null;
        } catch (Throwable) {
            return null;
        } finally {
            self::deleteDirectory($workDir);
        }
    }

    /**
     * @param  list<string>  $pdfBinaries
     */
    protected static function mergeWithPhpFpdiCompat(array $pdfBinaries): ?string
    {
        if (! class_exists(\setasign\Fpdi\Fpdi::class)) {
            return null;
        }

        try {
            /** @var \setasign\Fpdi\Fpdi $pdf */
            $pdf = new \setasign\Fpdi\Fpdi;
            $workDir = storage_path('app/tmp/pdfmerge_'.Str::lower((string) Str::uuid()));
            if (! is_dir($workDir) && ! mkdir($workDir, 0775, true) && ! is_dir($workDir)) {
                return null;
            }

            foreach ($pdfBinaries as $index => $binary) {
                $path = $workDir.DIRECTORY_SEPARATOR.sprintf('in_%03d.pdf', $index);
                file_put_contents($path, $binary);
                $pageCount = $pdf->setSourceFile($path);
                for ($page = 1; $page <= $pageCount; $page++) {
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($template);
                }
            }

            $merged = $pdf->Output('S');
            self::deleteDirectory($workDir);

            return is_string($merged) && str_starts_with($merged, '%PDF') ? $merged : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $candidates
     */
    protected static function firstAvailableBinary(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            try {
                $result = Process::timeout(10)->run([$candidate, '-v']);
                $combined = $result->errorOutput().$result->output();
                if (str_contains($combined, 'Ghostscript') || str_contains($combined, 'GPL Ghostscript')) {
                    return $candidate;
                }
            } catch (Throwable) {
                // try next probe
            }

            try {
                $result = Process::timeout(10)->run([$candidate, '--version']);
                if ($result->successful()) {
                    return $candidate;
                }
            } catch (Throwable) {
                // try next
            }
        }

        return null;
    }

    protected static function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($directory);
    }
}
