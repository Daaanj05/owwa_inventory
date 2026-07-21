<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class LibreOfficePdfConverter
{
    public function isEnabled(): bool
    {
        return (bool) config('services.libreoffice.enabled', true);
    }

    public function binary(): string
    {
        return $this->resolveBinary();
    }

    public function isAvailable(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return $this->probeBinary($this->resolveBinary());
    }

    /**
     * Resolve the LibreOffice executable. On Windows, when config is bare "soffice"
     * and PATH lookup fails, try common Program Files install paths.
     */
    public function resolveBinary(?string $configured = null): string
    {
        $configured = $configured ?? (string) config('services.libreoffice.binary', 'soffice');
        $configured = trim($configured);

        if ($configured === '') {
            $configured = 'soffice';
        }

        if ($this->probeBinary($configured)) {
            return $configured;
        }

        if (! $this->isBareSofficeName($configured) || ! $this->isWindowsOs()) {
            return $configured;
        }

        foreach ($this->windowsCandidateBinaries() as $candidate) {
            if ($this->probeBinary($candidate)) {
                return $candidate;
            }
        }

        return $configured;
    }

    /**
     * @return list<string>
     */
    public function windowsCandidateBinaries(): array
    {
        $candidates = [];

        foreach ([
            getenv('ProgramFiles') ?: 'C:\\Program Files',
            getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)',
            getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA').'\\Programs' : null,
        ] as $root) {
            if (! is_string($root) || $root === '') {
                continue;
            }

            $candidates[] = $root.'\\LibreOffice\\program\\soffice.exe';
        }

        return array_values(array_unique($candidates));
    }

    public function isBareSofficeName(string $binary): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $binary));

        return in_array($normalized, ['soffice', 'soffice.exe'], true);
    }

    /**
     * Convert XLSX bytes to PDF via LibreOffice headless.
     * Returns null when LibreOffice is unavailable or conversion fails.
     */
    public function convertXlsxBinary(string $xlsxBinary): ?string
    {
        if ($xlsxBinary === '' || ! $this->isEnabled()) {
            return null;
        }

        $workDir = storage_path('app/tmp/lo_'.Str::lower((string) Str::uuid()));

        try {
            if (! is_dir($workDir) && ! mkdir($workDir, 0775, true) && ! is_dir($workDir)) {
                return null;
            }

            $inputPath = $workDir.DIRECTORY_SEPARATOR.'export.xlsx';
            $outputPath = $workDir.DIRECTORY_SEPARATOR.'export.pdf';

            if (file_put_contents($inputPath, $xlsxBinary) === false) {
                return null;
            }

            $timeout = max(15, (int) config('services.libreoffice.timeout', 90));

            $profileUri = 'file:///'.str_replace('\\', '/', $workDir.'/lo_profile');

            $result = Process::timeout($timeout)
                ->env($this->processEnvironment($workDir))
                ->run([
                    $this->resolveBinary(),
                    '-env:UserInstallation='.$profileUri,
                    '--headless',
                    '--nologo',
                    '--nofirststartwizard',
                    '--norestore',
                    '--convert-to',
                    'pdf:calc_pdf_Export',
                    '--outdir',
                    $workDir,
                    $inputPath,
                ]);

            if (! $result->successful() || ! is_file($outputPath)) {
                Log::warning('LibreOffice PDF conversion failed.', [
                    'exit_code' => $result->exitCode(),
                    'stderr' => mb_substr($result->errorOutput(), 0, 1000),
                    'stdout' => mb_substr($result->output(), 0, 1000),
                ]);

                return null;
            }

            $pdf = file_get_contents($outputPath);

            if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF')) {
                return null;
            }

            return $pdf;
        } catch (Throwable $exception) {
            Log::warning('LibreOffice PDF conversion threw.', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        } finally {
            $this->deleteDirectory($workDir);
        }
    }

    protected function isWindowsOs(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    protected function probeBinary(string $binary): bool
    {
        try {
            $result = Process::timeout(15)
                ->env($this->processEnvironment(sys_get_temp_dir()))
                ->run([$binary, '--version']);

            return $result->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    protected function processEnvironment(string $workDir): array
    {
        return [
            'HOME' => $workDir,
            'TMPDIR' => $workDir,
            'SAL_USE_VCLPLUGIN' => 'svp',
        ];
    }

    protected function deleteDirectory(string $directory): void
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
