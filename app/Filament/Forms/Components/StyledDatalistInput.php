<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\TextInput;

class StyledDatalistInput extends TextInput
{
    /**
     * @var view-string
     */
    protected string $view = 'filament.forms.components.styled-datalist-input';

    /**
     * @var array<int, string> | Closure
     */
    protected array|Closure $suggestions = [];

    /**
     * @param  array<int, string> | Closure  $suggestions
     */
    public function suggestions(array|Closure $suggestions): static
    {
        $this->suggestions = $suggestions;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getSuggestions(): array
    {
        $suggestions = $this->evaluate($this->suggestions);

        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            is_array($suggestions) ? $suggestions : [],
        )));
    }
}
