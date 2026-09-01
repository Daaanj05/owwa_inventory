<?php

namespace Tests\Feature;

use App\Support\ItemPropertyClass;
use App\Support\OwwaExportDownloadCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesSemiExpendableAnnexA4Fixtures;
use Tests\TestCase;

class AnnexA4PdfExportTest extends TestCase
{
    use CreatesSemiExpendableAnnexA4Fixtures;
    use RefreshDatabase;

    public function test_annex_a4_pdf_export_downloads_pdf(): void
    {
        $this->skipUnlessLibreOfficeAvailable();

        $fixture = $this->createSemiItemWithIssuance(ItemPropertyClass::OfficeEquipment, 'Desk Organizer');
        $token = 'owwaPdfToken99';

        $response = $this->actingAs($fixture['custodian'])->get(route('owwa.export.bulk.annex-a4', [
            'category' => $fixture['category']->id,
            'format' => 'pdf',
            OwwaExportDownloadCookie::TOKEN_QUERY => $token,
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertCookie(OwwaExportDownloadCookie::DONE_COOKIE, $token);
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
