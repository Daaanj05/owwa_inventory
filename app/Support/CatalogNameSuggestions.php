<?php

namespace App\Support;

use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

class CatalogNameSuggestions
{
    /**
     * Suggest saved catalog names only. The browser datalist is not used, so a
     * value typed into the field is not kept in the dropdown before the item is saved.
     *
     * @param  Closure(Get): list<string>  $suggestions
     */
    public static function apply(TextInput $field, Closure $suggestions): TextInput
    {
        return $field
            ->autocomplete(false)
            ->extraInputAttributes(function (Get $get) use ($suggestions): array {
                $options = [];

                foreach ($suggestions($get) as $option) {
                    if (! is_string($option)) {
                        continue;
                    }

                    $option = trim($option);
                    if ($option === '') {
                        continue;
                    }

                    $options[] = $option;
                }

                $encoded = json_encode(array_values($options), JSON_UNESCAPED_UNICODE);

                return [
                    'data-owwa-saved-suggestions' => $encoded === false ? '[]' : $encoded,
                    'data-1p-ignore' => 'true',
                    'data-lpignore' => 'true',
                ];
            });
    }
}
