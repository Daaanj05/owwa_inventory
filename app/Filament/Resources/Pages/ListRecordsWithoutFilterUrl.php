<?php

namespace App\Filament\Resources\Pages;

use Filament\Resources\Pages\ListRecords;

/**
 * Same as Filament ListRecords, but table filters stay out of the query string.
 * Refreshing the page clears From/To. Category still uses #[Url] on $category.
 */
abstract class ListRecordsWithoutFilterUrl extends ListRecords
{
    /**
     * @var array<string, mixed> | null
     */
    public ?array $tableFilters = null;
}
