<?php

namespace Tests\Feature;

use App\Filament\Resources\Departments\DepartmentResource;
use Tests\TestCase;

class SystemAdminOfficeSetupTest extends TestCase
{
    public function test_office_form_excludes_fund_cluster_and_signatory_fields(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Offices/Schemas/OfficeForm.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString("TextInput::make('fund_cluster')", $source);
        $this->assertStringNotContainsString("TextInput::make('supply_custodian_name')", $source);
        $this->assertStringNotContainsString('Default signatories', $source);
    }

    public function test_user_form_includes_unit_consolidator_assignment_repeater(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Users/Schemas/UserForm.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("Repeater::make('office_groups')", $source);
        $this->assertStringContainsString('Sub-Office/Department', $source);
        $this->assertStringContainsString('owwa-uc-office-groups-repeater', $source);
        $this->assertStringContainsString('Add sub-office/department', $source);
        $this->assertStringContainsString('->table([', $source);
        $this->assertStringContainsString('->simple(', $source);
        $this->assertStringContainsString('deleteAction(', $source);
        $this->assertStringNotContainsString('owwa-uc-assignments-table-repeater', $source);
    }

    public function test_department_resource_uses_sub_office_labels(): void
    {
        $this->assertSame('Sub-Office/Departments', DepartmentResource::getNavigationLabel());
        $this->assertSame('Sub-Office/Department', DepartmentResource::getModelLabel());
        $this->assertSame('Sub-Office/Departments', DepartmentResource::getPluralModelLabel());
    }

    public function test_office_view_infolist_omits_name_and_status_duplicated_by_hero(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Offices/Schemas/OfficeInfolist.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString("TextEntry::make('name')", $source);
        $this->assertStringNotContainsString('archivedStatusEntry', $source);
        $this->assertStringContainsString("TextEntry::make('code')", $source);
        $this->assertStringContainsString("TextEntry::make('address')", $source);
    }
}
