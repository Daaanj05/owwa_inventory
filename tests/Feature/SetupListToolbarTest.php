<?php

namespace Tests\Feature;

use App\Filament\Resources\Offices\Pages\ListOffices;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SetupListToolbarTest extends TestCase
{
    use RefreshDatabase;

    public function test_office_list_puts_new_office_on_the_search_row_and_archive_icons_filter_the_list(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $admin = User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);
        $this->actingAs($admin);

        $active = Office::factory()->create(['name' => 'Active Regional Office']);
        $archived = Office::factory()->create(['name' => 'Archived Field Office']);
        $archived->forceFill(['archived_at' => now()])->save();

        $component = Livewire::test(ListOffices::class)
            ->assertSeeHtml('owwa-wizard-title')
            ->assertSeeHtml('owwa-search-row-actions')
            ->assertSeeHtml('owwa-setup-archive-view-toggle')
            ->assertSeeHtml('aria-label="Active"')
            ->assertSeeHtml('aria-label="Archived"')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$archived]);

        $html = $component->html();
        $this->assertMatchesRegularExpression(
            '/owwa-search-row-actions[\s\S]*New Office/',
            $html,
        );
        $this->assertStringNotContainsString('owwa-setup-list-page-actions', $html);
        $this->assertStringNotContainsString('fi-header-actions-ctn', $html);

        $component
            ->set('activeTab', 'archived')
            ->assertCanNotSeeTableRecords([$active])
            ->assertCanSeeTableRecords([$archived]);
    }

    public function test_wizard_title_icon_hide_applies_only_when_the_title_already_has_an_icon(): void
    {
        $source = file_get_contents(resource_path('css/owwa/_pages.css'));

        $this->assertIsString($source);
        $this->assertStringContainsString(
            '.fi-page .fi-header-heading:has(.owwa-wizard-step-icon)::before',
            $source,
        );
        $this->assertStringNotContainsString(
            '.fi-page .fi-header-heading:has(.owwa-wizard-title)::before',
            $source,
        );
    }
}
