<?php

namespace Tests\Feature;

use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Support\OwwaExportDownloadCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwwaExportBusyCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_response_sets_download_done_cookie_even_on_not_found(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $token = 'owwaTestToken12';

        $response = $this->actingAs($custodian)->get(route('owwa.export.bulk.stock-cards', [
            'category' => $category->id,
            OwwaExportDownloadCookie::TOKEN_QUERY => $token,
        ]));

        $response->assertNotFound();
        $response->assertCookie(OwwaExportDownloadCookie::DONE_COOKIE, $token);
    }
}
