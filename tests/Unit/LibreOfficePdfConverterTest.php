<?php

namespace Tests\Unit;

use App\Services\LibreOfficePdfConverter;
use Tests\TestCase;

class LibreOfficePdfConverterTest extends TestCase
{
    public function test_windows_candidate_binaries_include_program_files_paths(): void
    {
        $candidates = app(LibreOfficePdfConverter::class)->windowsCandidateBinaries();

        $this->assertNotEmpty($candidates);
        $this->assertTrue(collect($candidates)->contains(
            fn (string $path): bool => str_ends_with(str_replace('/', '\\', $path), 'LibreOffice\\program\\soffice.exe')
        ));
    }

    public function test_is_bare_soffice_name_detects_pathless_binary(): void
    {
        $converter = app(LibreOfficePdfConverter::class);

        $this->assertTrue($converter->isBareSofficeName('soffice'));
        $this->assertTrue($converter->isBareSofficeName('soffice.exe'));
        $this->assertFalse($converter->isBareSofficeName('C:/Program Files/LibreOffice/program/soffice.exe'));
    }

    public function test_resolve_binary_keeps_explicit_path_when_probe_fails(): void
    {
        $converter = new class extends LibreOfficePdfConverter
        {
            protected function isWindowsOs(): bool
            {
                return true;
            }

            protected function probeBinary(string $binary): bool
            {
                return false;
            }
        };

        $resolved = $converter->resolveBinary('C:/Program Files/LibreOffice/program/soffice.exe');

        $this->assertSame('C:/Program Files/LibreOffice/program/soffice.exe', $resolved);
    }

    public function test_resolve_binary_falls_back_to_windows_candidate_when_bare_soffice_fails(): void
    {
        $converter = new class extends LibreOfficePdfConverter
        {
            protected function isWindowsOs(): bool
            {
                return true;
            }

            protected function probeBinary(string $binary): bool
            {
                return str_ends_with(str_replace('/', '\\', $binary), 'LibreOffice\\program\\soffice.exe');
            }

            public function windowsCandidateBinaries(): array
            {
                return ['C:\\Program Files\\LibreOffice\\program\\soffice.exe'];
            }
        };

        $resolved = $converter->resolveBinary('soffice');

        $this->assertSame('C:\\Program Files\\LibreOffice\\program\\soffice.exe', $resolved);
    }
}
