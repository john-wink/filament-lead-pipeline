<div style="margin-bottom: 0.25rem;">
    <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: var(--lp-text); opacity: 0.9;">
        {{ $field->definition->name }}
        @if($field->is_required) <span style="color: var(--lp-error);">*</span> @endif
    </label>
    <input
        type="text"
        wire:model="formData.{{ $key }}"
        placeholder="{{ $field->placeholder ?? '' }}"
        style="width: 100%; padding: 0.875rem 1.25rem; border: 1px solid var(--lp-border); border-radius: var(--lp-radius); font-size: 1rem; font-family: inherit; color: var(--lp-text); background: var(--lp-field-bg); outline: none; transition: border-color 0.2s, box-shadow 0.2s; backdrop-filter: blur(4px);"
        onfocus="this.style.borderColor='var(--lp-primary)'; this.style.boxShadow='0 0 0 3px color-mix(in srgb, var(--lp-primary) 25%, transparent)';"
        onblur="this.style.borderColor='var(--lp-border)'; this.style.boxShadow='none';"
    >
    @if($field->help_text)
        <p style="font-size: 0.75rem; margin-top: 0.375rem; color: var(--lp-subtle);">{{ $field->help_text }}</p>
    @endif
    @error("formData.{$key}")
        <p style="font-size: 0.75rem; margin-top: 0.375rem; color: var(--lp-error);">{{ $message }}</p>
    @enderror
</div>
