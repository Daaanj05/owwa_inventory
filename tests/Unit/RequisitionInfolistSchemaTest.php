<?php

namespace Tests\Unit;

use App\Filament\Resources\Requisitions\Schemas\RequisitionInfolistSchema;
use Tests\TestCase;

class RequisitionInfolistSchemaTest extends TestCase
{
    public function test_requested_items_section_uses_slim_column_layouts(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Requisitions/Schemas/RequisitionInfolistSchema.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("TableColumn::make('Qty')", $source);
        $this->assertStringContainsString("TableColumn::make('Status')", $source);
        $this->assertStringContainsString('employeeRequestedItemsRepeatable', $source);
        $this->assertStringContainsString('consolidatedRequestedItemsRepeatable', $source);
        $this->assertStringNotContainsString("TableColumn::make('Stock at request')", $source);
        $this->assertStringNotContainsString("TableColumn::make('Distributed')", $source);
        $this->assertStringNotContainsString("TableColumn::make('Fulfillment')", $source);
    }

    public function test_requested_items_section_is_exposed_for_modal(): void
    {
        $sections = RequisitionInfolistSchema::modalDetailSections();

        $this->assertCount(3, $sections);
        $this->assertSame('Requested items', $sections[2]->getHeading());
    }
}
