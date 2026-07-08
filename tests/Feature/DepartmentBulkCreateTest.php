<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Office;
use App\Services\DepartmentBulkCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DepartmentBulkCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_multiple_departments_for_one_office(): void
    {
        $office = Office::factory()->create();

        $created = app(DepartmentBulkCreateService::class)->createForOffice($office->id, [
            ['name' => 'Operations Division', 'code' => 'OPS'],
            ['name' => 'Finance Division', 'code' => 'FIN'],
            ['name' => 'Administrative Division', 'code' => 'ADM'],
        ]);

        $this->assertCount(3, $created);
        $this->assertDatabaseCount('departments', 3);
        $this->assertDatabaseHas('departments', [
            'office_id' => $office->id,
            'name' => 'Finance Division',
            'code' => 'FIN',
        ]);
    }

    public function test_rejects_duplicate_names_within_form_submission(): void
    {
        $office = Office::factory()->create();

        $this->expectException(ValidationException::class);

        try {
            app(DepartmentBulkCreateService::class)->createForOffice($office->id, [
                ['name' => 'Operations Division', 'code' => 'OPS'],
                ['name' => 'operations division', 'code' => 'OPS2'],
            ]);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.1.name', $exception->errors());

            throw $exception;
        }
    }

    public function test_rejects_name_that_already_exists_in_office(): void
    {
        $office = Office::factory()->create();

        Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations Division',
            'code' => 'OPS',
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(DepartmentBulkCreateService::class)->createForOffice($office->id, [
                ['name' => 'Operations Division', 'code' => 'OPS2'],
            ]);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.name', $exception->errors());

            throw $exception;
        }
    }

    public function test_edit_form_uses_single_name_field_not_repeater(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Departments/Schemas/DepartmentForm.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("Repeater::make('lines')", $source);
        $this->assertStringContainsString('->table([', $source);
        $this->assertStringNotContainsString("Section::make('Sub-Office/Department details')", $source);
        $this->assertStringContainsString('isEditOperation', $source);
        $this->assertStringContainsString("TextInput::make('name')", $source);
        $this->assertStringContainsString('UniqueDepartmentNameInOffice', $source);
    }
}
