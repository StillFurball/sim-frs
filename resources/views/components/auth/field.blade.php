@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'note' => null,
    'viewable' => false,
])

@php
    $id = $attributes->get('id', $name);
    $describedBy = collect([$note ? $id.'-note' : null, $errors->has($name) ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div class="field">
    <label class="field__label" for="{{ $id }}">{{ $label }}</label>
    <div class="field__control">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $type }}"
            @if ($value !== null) value="{{ $value }}" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @error($name) aria-invalid="true" @enderror
            {{ $attributes->except('id')->merge(['class' => 'field__input']) }}
        >
        @if ($viewable)
            <button type="button" class="field__toggle" data-toggle-password="{{ $id }}" aria-controls="{{ $id }}" aria-pressed="false" data-label-show="{{ __('Show') }}" data-label-hide="{{ __('Hide') }}">{{ __('Show') }}</button>
        @endif
    </div>
    @if ($note)
        <p class="field__note" id="{{ $id }}-note">{{ $note }}</p>
    @endif
    @error($name)
        <p class="field__error" id="{{ $id }}-error">{{ $message }}</p>
    @enderror
</div>
