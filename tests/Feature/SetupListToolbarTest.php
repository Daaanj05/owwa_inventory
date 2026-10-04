<?php

namespace Tests\Feature;

use App\Filament\Resources\Offices\Pages\ListOffices;
use App\Filament\Resources\PropertyActionRequests\Pages\ListPropertyActionRequests;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Models\Office;
use App\Models\PropertyActionRequest;
use App\Models\Requisition;
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

    public function test_employee_requisition_list_uses_search_row_new_and_archive_icons(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $this->actingAs($employee);

        $active = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_DRAFT,
        ]);
        $archived = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_DRAFT,
            'archived_at' => now(),
        ]);

        $component = Livewire::test(ListRequisitions::class)
            ->assertSeeHtml('owwa-search-row-actions')
            ->assertSeeHtml('owwa-setup-archive-view-toggle')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$archived]);

        $html = $component->html();
        $this->assertMatchesRegularExpression(
            '/owwa-search-row-actions[\s\S]*New Requisition/',
            $html,
        );
        $this->assertStringNotContainsString('fi-header-actions-ctn', $html);

        $component
            ->set('activeTab', 'archived')
            ->assertCanNotSeeTableRecords([$active])
            ->assertCanSeeTableRecords([$archived]);
    }

    public function test_employee_property_return_list_uses_search_row_new_and_archive_icons(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $this->actingAs($employee);

        $active = PropertyActionRequest::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'status' => PropertyActionRequest::STATUS_DRAFT,
        ]);
        $archived = PropertyActionRequest::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'status' => PropertyActionRequest::STATUS_DRAFT,
            'archived_at' => now(),
        ]);

        $component = Livewire::test(ListPropertyActionRequests::class)
            ->assertSeeHtml('owwa-search-row-actions')
            ->assertSeeHtml('owwa-setup-archive-view-toggle')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$archived]);

        $html = $component->html();
        $this->assertMatchesRegularExpression(
            '/owwa-search-row-actions[\s\S]*New Property Return/',
            $html,
        );
        $this->assertStringNotContainsString('fi-header-actions-ctn', $html);

        $component
            ->set('activeTab', 'archived')
            ->assertCanNotSeeTableRecords([$active])
            ->assertCanSeeTableRecords([$archived]);
    }
}
