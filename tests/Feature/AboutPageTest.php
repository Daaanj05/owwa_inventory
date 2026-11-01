<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use App\Support\AboutModalAction;
use App\Support\SystemCredits;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_credits_hardcode_all_developers(): void
    {
        $this->assertSame([
            'Dan Jediael P. Caranay',
            'Jonathan M. Ilao',
            'Jennilyn B. Pataueg',
            'Carl Gabriel E. Pesigan',
        ], SystemCredits::DEVELOPERS);

        $this->assertSame('Prof. Jester Emann Peñaranda', SystemCredits::ADVISER);
        $this->assertSame('City College of Calamba', SystemCredits::INSTITUTION);
        $this->assertFileDoesNotExist(config_path('owwa_about.php'));
    }

    public function test_about_modal_content_includes_project_and_developers(): void
    {
        $html = view('filament.pages.partials.about-content', [
            'about' => SystemCredits::about(),
        ])->render();

        $this->assertStringContainsString(SystemCredits::SYSTEM_NAME, $html);
        $this->assertStringContainsString('Regional Welfare Office IV-A', $html);
        $this->assertStringContainsString(SystemCredits::PROJECT_TYPE, $html);
        $this->assertStringContainsString(SystemCredits::INSTITUTION, $html);
        $this->assertStringContainsString(SystemCredits::ADVISER, $html);
        $this->assertStringContainsString('Developed by', $html);
        $this->assertStringContainsString('inventory system with procurement analytics', $html);
        $this->assertStringContainsString('acquisitions, issuances, requisitions, physical counts', $html);
        $this->assertStringContainsString('owwa-about-developers', $html);
        $this->assertStringContainsString('owwa-about-logo--owwa', $html);
        $this->assertStringNotContainsString('<img', $html);

        foreach (SystemCredits::DEVELOPERS as $developer) {
            $this->assertStringContainsString($developer, $html);
        }
    }

    public function test_about_user_menu_action_opens_modal_without_page_url(): void
    {
        $action = AboutModalAction::make();

        $this->assertSame('About', $action->getLabel());
        $this->assertNull($action->getUrl());
        $this->assertSame('About this system', $action->getModalHeading());
        $this->assertNull($action->getModalSubmitAction());
    }

    public function test_about_page_route_is_removed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($custodian)
            ->get('/about')
            ->assertNotFound();
    }
}
