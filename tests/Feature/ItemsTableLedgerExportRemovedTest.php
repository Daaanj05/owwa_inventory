<?php

namespace Tests\Feature;

use Tests\TestCase;

class ItemsTableLedgerExportRemovedTest extends TestCase
{
    public function test_items_table_does_not_register_ledger_export_action(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Items/Tables/ItemsTable.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('exportOwwaItemReport', $source);
        $this->assertStringNotContainsString('ItemViewActions', $source);
    }
}
