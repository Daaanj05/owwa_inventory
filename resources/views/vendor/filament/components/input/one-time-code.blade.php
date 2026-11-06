@props([
    'length' => 6,
])

<div
    x-data="{ currentNumberOfDigits: null, code: '' }"
    {{
        $attributes
            ->class([
                'fi-one-time-code-input-ctn',
            ])
    }}
>
    @foreach (range(1, $length) as $digit)
        <div
            x-bind:class="{
                'fi-filled':
                    currentNumberOfDigits !== null &&
                    currentNumberOfDigits >= {{ $digit }},
                'fi-active':
                    currentNumberOfDigits !== null &&
                    (currentNumberOfDigits + 1) === {{ $digit }},
            }"
            class="fi-one-time-code-input-digit-field"
            x-text="code.charAt({{ $digit - 1 }})"
        ></div>
    @endforeach

    <input
        autocomplete="one-time-code"
        inputmode="numeric"
        minlength="{{ $length }}"
        maxlength="{{ $length }}"
        pattern="\d{{ '{' . $length . '}' }}"
        type="text"
        x-on:focus="currentNumberOfDigits = $el.value.length; code = $el.value"
        x-on:blur="currentNumberOfDigits = null"
        x-on:input="
            $el.value = $el.value.replace(/\D/g, '')
            code = $el.value
            currentNumberOfDigits = $el.value.length
        "
        x-bind:class="{ 'fi-valid': currentNumberOfDigits >= {{ $length }} }"
        {{ $input?->attributes }}
        class="fi-one-time-code-input"
    />
</div>
