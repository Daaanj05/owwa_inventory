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
        $this->assertStringContainsString("TableColumn::make('Available stock')", $source);
        $this->assertStringContainsString("TableColumn::make('Issue remarks')", $source);
        $this->assertStringContainsString("TableColumn::make('Endorsement note')", $source);
        $this->assertStringContainsString('identifierHeaderForRequisition', $source);
        $this->assertStringContainsString('employeeRequestedItemsRepeatable', $source);
        $this->assertStringContainsString('consolidatedRequestedItemsRepeatable', $source);
        $this->assertStringNotContainsString("TableColumn::make('Restock')", $source);
        $this->assertStringNotContainsString('WorkflowEmailNotificationsInfolist', $source);
        $this->assertStringNotContainsString("TableColumn::make('Stock at request')", $source);
        $this->assertStringNotContainsString("TableColumn::make('Distributed')", $source);
        $this->assertStringNotContainsString("TableColumn::make('Fulfillment')", $source);
        $this->assertStringContainsString("'Processed by'", $source);
        $this->assertStringContainsString("'Date processed'", $source);
    }

    public function test_requested_items_section_is_exposed_for_modal(): void
    {
        $headings = array_map(
            fn ($section): ?string => $section->getHeading(),
            RequisitionInfolistSchema::modalDetailSections(),
        );

        $this->assertContains('Requested items', $headings);
        $this->assertContains('Printed RIS signatories', $headings);
        $this->assertNotContains('Email notifications', $headings);
    }
}
