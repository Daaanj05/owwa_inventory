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
        $this->assertStringContainsString('filled(self::processedByName($record))', $source);
        $this->assertStringContainsString('filled(self::processedAtText($record))', $source);
        $this->assertStringContainsString('employeeAllocationGroups', $source);
        $this->assertStringContainsString('employeeAllocationLinesForUser', $source);
        $this->assertStringContainsString('viewEmployeeAllocation_', $source);
        $this->assertStringContainsString('owwa-employee-allocation-row', $source);
        $this->assertStringContainsString('overlayParentActions()', $source);
        $this->assertStringContainsString('catalogIdentifierForItem', $source);
        $this->assertStringNotContainsString("'View items'", $source);
        $this->assertStringContainsString('sortedRequestedItems', $source);
        $this->assertStringContainsString('sortLinesForDisplay', $source);

        $endorsementIdentifier = self::methodSource($source, 'endorsementIdentifier');
        $this->assertStringContainsString('catalogIdentifierForItem', $endorsementIdentifier);
        $this->assertStringNotContainsString('identifierValue', $endorsementIdentifier);
        $this->assertStringNotContainsString('issuances', $endorsementIdentifier);

        $groups = self::methodSource($source, 'employeeAllocationGroups');
        $this->assertStringNotContainsString('endorsementLineState', $groups);
        $this->assertStringContainsString("'line_count'", $groups);
    }

    protected static function methodSource(string $source, string $method): string
    {
        if (preg_match('/function '.$method.'\b.*?\n    (?:protected|public|private) static function /s', $source, $match) !== 1) {
            if (preg_match('/function '.$method.'\b.*?\n\}/s', $source, $match) !== 1) {
                return '';
            }
        }

        return $match[0];
    }

    public function test_requested_items_section_is_exposed_for_modal(): void
    {
        $headings = array_map(
            fn ($section): ?string => $section->getHeading(),
            RequisitionInfolistSchema::modalDetailSections(),
        );

        $this->assertContains('Requested items', $headings);
        $this->assertContains('Printed RIS signatories', $headings);
        $this->assertContains('Employee allocations', $headings);
        $this->assertNotContains('Email notifications', $headings);
    }
}
