<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

trait HasOwwaViewModalUrl
{
    /**
     * @param  array<string, mixed>  $extraParams
     */
    public static function viewModalUrl(Model|int $record, array $extraParams = []): string
    {
        $id = $record instanceof Model ? $record->getKey() : $record;

        $params = array_merge([
            'tableAction' => 'view',
            'tableActionRecord' => $id,
        ], $extraParams);

        if ($categoryId = session('active_item_category_id')) {
            $params['category'] ??= $categoryId;
        }

        return static::getUrl('index', $params);
    }
}
