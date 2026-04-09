<div style="margin-bottom: 0.25rem;" x-data="{ selected: @js($value ?? null) }">
    <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.75rem; color: var(--lp-text); opacity: 0.9;">
        {{ $field->definition->name }}
        @if($field->is_required) <span style="color: var(--lp-error);">*</span> @endif
    </label>
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem;">
        <button
            type="button"
            wire:click="$set('formData.{{ $key }}', true)"
            x-on:click="selected = true"
            x-bind:style="selected === true
                ? 'border-color: var(--lp-primary); background: var(--lp-selected-bg); box-shadow: 0 0 0 1px var(--lp-primary);'
                : 'border-color: var(--lp-border); background: var(--lp-field-bg); box-shadow: none;'"
            style="padding: 1.25rem; border: 1px solid var(--lp-border); border-radius: var(--lp-radius); cursor: pointer; transition: all 0.15s; text-align: center; font-family: inherit; font-size: 1.125rem; font-weight: 600; color: var(--lp-text); min-height: 56px; backdrop-filter: blur(4px);"
        >
            Ja
        </button>
        <button
            type="button"
            wire:click="$set('formData.{{ $key }}', false)"
            x-on:click="selected = false"
            x-bind:style="selected === false
                ? 'border-color: var(--lp-primary); background: var(--lp-selected-bg); box-shadow: 0 0 0 1px var(--lp-primary);'
                : 'border-color: var(--lp-border); background: var(--lp-field-bg); box-shadow: none;'"
            style="padding: 1.25rem; border: 1px solid var(--lp-border); border-radius: var(--lp-radius); cursor: pointer; transition: all 0.15s; text-align: center; font-family: inherit; font-size: 1.125rem; font-weight: 600; color: var(--lp-text); min-height: 56px; backdrop-filter: blur(4px);"
        >
            Nein
        </button>
    </div>
    @error("formData.{$key}")
        <p style="font-size: 0.75rem; margin-top: 0.375rem; color: var(--lp-error);">{{ $message }}</p>
    @enderror
</div>
