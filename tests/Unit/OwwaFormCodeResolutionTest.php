<?php

namespace Tests\Unit;

use App\Services\OwwaTemplateExportService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OwwaFormCodeResolutionTest extends TestCase
{
    private OwwaTemplateExportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OwwaTemplateExportService::class);
    }

    #[DataProvider('templatePathProvider')]
    public function test_resolve_owwa_form_code(string $templatePath, string $expectedFormCode): void
    {
        $this->assertSame(
            $expectedFormCode,
            $this->service->resolveOwwaFormCode($templatePath),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function templatePathProvider(): array
    {
        return [
            'ppe property card appendix 69' => [
                'ppe/Accquisition/Appendix 69 - PC.xls',
                'PC',
            ],
            'semi annex a1 property card' => [
                'Semi-Expendable/Recording (Stock Levels)/Property-Form-Annex-A.1-Semi-expendable-Property-Card.xlsx',
                'AnnexA1',
            ],
            'consumable stock card appendix 58' => [
                'Consumable/Recording (Stock Levels)/Appendix 58 - SC.xls',
                'SC',
            ],
            'ppe par appendix 71' => [
                'ppe/Issuance/Appendix 71 - PAR.xls',
                'PAR',
            ],
            'semi ics appendix 59' => [
                'Semi-Expendable/Issuance/Appendix 59 - ICS.xls',
                'ICS',
            ],
            'semi iirusp annex a10' => [
                'Semi-Expendable/Disposal/Annex A.10 - IIRUSP.xlsx',
                'IIRUSP',
            ],
            'unknown template falls back to owwa' => [
                'some/unknown/template.xlsx',
                'OWWA',
            ],
        ];
    }
}
