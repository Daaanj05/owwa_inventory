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
        $this->assertMatchesRegularExpression(
            "/TableColumn::make\\('Category'\\),\\s*TableColumn::make\\('Item'\\),\\s*TableColumn::make\\(OwwaReferenceLabels::assetIdentifierTableHeader\\(\\)\\),\\s*TableColumn::make\\('Available stock'\\),\\s*TableColumn::make\\('Requested'\\),\\s*TableColumn::make\\('Issued'\\),\\s*TableColumn::make\\('Remaining'\\),\\s*TableColumn::make\\('Status'\\),\\s*TableColumn::make\\('Restock'\\),\\s*TableColumn::make\\('Remarks'\\),/s",
            $source,
        );
        $this->assertStringContainsString('employeeRequestedItemsRepeatable', $source);
        $this->assertStringContainsString('consolidatedRequestedItemsRepeatable', $source);
        $this->assertStringNotContainsString("TableColumn::make('Stock at request')", $source);
        $this->assertStringNotContainsString("TableColumn::make('Distributed')", $source);
        $this->assertStringNotContainsString("TableColumn::make('Fulfillment')", $source);
    }

    public function test_requested_items_section_is_exposed_for_modal(): void
    {
        $headings = array_map(
            fn ($section): ?string => $section->getHeading(),
            RequisitionInfolistSchema::modalDetailSections(),
        );

        $this->assertContains('Requested items', $headings);
    }
}
