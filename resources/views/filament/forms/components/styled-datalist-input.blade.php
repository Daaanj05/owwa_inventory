@php
    $fieldWrapperView = $getFieldWrapperView();
    $extraAlpineAttributes = $getExtraAlpineAttributes();
    $extraAttributeBag = $getExtraAttributeBag();
    $id = $getId();
    $isConcealed = $isConcealed();
    $isDisabled = $isDisabled();
    $isPrefixInline = $isPrefixInline();
    $isSuffixInline = $isSuffixInline();
    $prefixActions = $getPrefixActions();
    $prefixIcon = $getPrefixIcon();
    $prefixIconColor = $getPrefixIconColor();
    $prefixLabel = $getPrefixLabel();
    $suffixActions = $getSuffixActions();
    $suffixIcon = $getSuffixIcon();
    $suffixIconColor = $getSuffixIconColor();
    $suffixLabel = $getSuffixLabel();
    $statePath = $getStatePath();
    $placeholder = $getPlaceholder();
    $suggestions = $getSuggestions();
    $type = $getType();

    $inputAttributes = $getExtraInputAttributeBag()
        ->merge($extraAlpineAttributes, escape: false)
        ->merge([
            'autocapitalize' => $getAutocapitalize(),
            'autocomplete' => 'off',
            'autofocus' => $isAutofocused(),
            'disabled' => $isDisabled,
            'id' => $id,
            'inlinePrefix' => $isPrefixInline && (count($prefixActions) || $prefixIcon || filled($prefixLabel)),
            'inlineSuffix' => $isSuffixInline && (count($suffixActions) || $suffixIcon || filled($suffixLabel)),
            'inputmode' => $getInputMode(),
            'max' => (! $isConcealed) ? $getMaxValue() : null,
            'maxlength' => (! $isConcealed) ? $getMaxLength() : null,
            'min' => (! $isConcealed) ? $getMinValue() : null,
            'minlength' => (! $isConcealed) ? $getMinLength() : null,
            'placeholder' => filled($placeholder) ? e($placeholder) : null,
            'readonly' => $isReadOnly(),
            'required' => $isRequired() && (! $isConcealed),
            'step' => $getStep(),
            'type' => $type,
            'x-ref' => 'input',
            'x-on:focus' => "syncFromInput(); open = (query || '').trim().length > 0",
            'x-on:input' => "syncFromInput(); open = (query || '').trim().length > 0",
            'x-on:keydown.escape.stop' => 'open = false',
            'x-on:keydown.arrow-down.prevent' => 'open = true',
            $applyStateBindingModifiers('wire:model') => $statePath,
        ], escape: false);
@endphp

<x-dynamic-component
    :component="$fieldWrapperView"
    :field="$field"
    :inline-label-vertical-alignment="\Filament\Support\Enums\VerticalAlignment::Center"
>
    <div
        class="owwa-styled-datalist"
        x-data="{
            open: false,
            query: '',
            suggestions: @js($suggestions),
            get filtered() {
                const q = (this.query || '').toLowerCase().trim()
                if (! q) {
                    return this.suggestions.slice(0, 40)
                }

                return this.suggestions
                    .filter((item) => item.toLowerCase().includes(q))
                    .slice(0, 40)
            },
            syncFromInput() {
                const input = this.$refs.input
                this.query = input ? input.value : ''
            },
            pick(value) {
                const input = this.$refs.input
                if (! input) {
                    return
                }

                input.value = value
                input.dispatchEvent(new Event('input', { bubbles: true }))
                this.query = value
                this.open = false
                input.focus()
            },
        }"
        x-on:click.outside="open = false"
        wire:ignore.self
    >
        <x-filament::input.wrapper
            :disabled="$isDisabled"
            :inline-prefix="$isPrefixInline"
            :inline-suffix="$isSuffixInline"
            :prefix="$prefixLabel"
            :prefix-actions="$prefixActions"
            :prefix-icon="$prefixIcon"
            :prefix-icon-color="$prefixIconColor"
            :suffix="$suffixLabel"
            :suffix-actions="$suffixActions"
            :suffix-icon="$suffixIcon"
            :suffix-icon-color="$suffixIconColor"
            :valid="! $errors->has($statePath)"
            x-on:focus-input.stop="$el.querySelector('input')?.focus()"
            :attributes="
                \Filament\Support\prepare_inherited_attributes($extraAttributeBag)
                    ->class(['fi-fo-text-input'])
            "
        >
            <input
                {{
                    $inputAttributes->class([
                        'fi-input',
                        'fi-input-has-inline-prefix' => $isPrefixInline && (count($prefixActions) || $prefixIcon || filled($prefixLabel)),
                        'fi-input-has-inline-suffix' => $isSuffixInline && (count($suffixActions) || $suffixIcon || filled($suffixLabel)),
                    ])
                }}
            />
        </x-filament::input.wrapper>

        <div
            x-show="open && filtered.length > 0"
            x-cloak
            class="owwa-styled-datalist-panel"
            role="listbox"
        >
            <template x-for="item in filtered" :key="item">
                <button
                    type="button"
                    class="owwa-styled-datalist-option"
                    role="option"
                    x-text="item"
                    x-on:mousedown.prevent="pick(item)"
                ></button>
            </template>
        </div>
    </div>
</x-dynamic-component>
