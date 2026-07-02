<?php

namespace Tests\Unit;

use App\Support\OwwaTemplateLoader;
use Tests\TestCase;

class OwwaTemplateLoaderTest extends TestCase
{
    protected function tearDown(): void
    {
        OwwaTemplateLoader::flush();

        parent::tearDown();
    }

    public function test_second_load_returns_independent_clone(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $path = storage_path('app/templates/Semi-Expendable/Recording (Stock Levels)/Property-Form-Annex-A.1-Semi-expendable-Property-Card.xlsx');
        if (! is_readable($path)) {
            $this->markTestSkipped('Annex A.1 template is not present in storage/app/templates.');
        }

        $first = OwwaTemplateLoader::load($path);
        $first->getActiveSheet()->setCellValue('A1', 'MUTATED');

        $second = OwwaTemplateLoader::load($path);

        $this->assertNotSame('MUTATED', $second->getActiveSheet()->getCell('A1')->getValue());
    }

    public function test_forget_forces_fresh_load(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        $path = storage_path('app/templates/Semi-Expendable/Recording (Stock Levels)/Property-Form-Annex-A.1-Semi-expendable-Property-Card.xlsx');
        if (! is_readable($path)) {
            $this->markTestSkipped('Annex A.1 template is not present in storage/app/templates.');
        }

        $first = OwwaTemplateLoader::load($path);
        $first->getActiveSheet()->setCellValue('A1', 'MUTATED');

        OwwaTemplateLoader::forget($path);

        $fresh = OwwaTemplateLoader::load($path);

        $this->assertNotSame('MUTATED', $fresh->getActiveSheet()->getCell('A1')->getValue());
    }
}
