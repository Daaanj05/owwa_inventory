<?php

namespace Tests\Feature;

use App\Services\OwwaTemplateExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class OwwaTemplateStrictModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_strict_mode_throws_when_template_missing(): void
    {
        config(['owwa_templates.strict' => true]);

        $service = app(OwwaTemplateExportService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OWWA template not found');

        $service->renderFilledSpreadsheet('missing/Appendix 99 - Does Not Exist.xls', [
            'A1' => 'test',
        ]);
    }

    public function test_non_strict_mode_falls_back_to_plain_workbook(): void
    {
        config(['owwa_templates.strict' => false]);

        $spreadsheet = app(OwwaTemplateExportService::class)->renderFilledSpreadsheet(
            'missing/Appendix 99 - Does Not Exist.xls',
            ['A1' => 'filled'],
        );

        $this->assertSame('filled', $spreadsheet->getActiveSheet()->getCell('A1')->getValue());
    }

    public function test_sync_templates_command_copies_files(): void
    {
        $this->assertDirectoryExists(resource_path('owwa-templates'));

        $exitCode = Artisan::call('owwa:sync-templates', ['--force' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertGreaterThan(0, count(File::allFiles(storage_path('app/templates'))));
    }
}
