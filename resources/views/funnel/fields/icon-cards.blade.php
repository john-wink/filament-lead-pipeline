<div style="margin-bottom: 0.25rem;" x-data="{ selected: @js($value ?? null) }">
    <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.75rem; color: var(--lp-text); opacity: 0.9;">
        {{ $field->definition->name }}
        @if($field->is_required) <span style="color: var(--lp-error);">*</span> @endif
    </label>
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem;">
        @foreach($field->funnel_options ?? [] as $option)
            <button
                type="button"
                wire:click="$set('formData.{{ $key }}', '{{ $option['value'] }}')"
                x-on:click="selected = '{{ $option['value'] }}'"
                x-bind:style="selected === '{{ $option['value'] }}'
                    ? 'border-color: var(--lp-primary); background: var(--lp-selected-bg); box-shadow: 0 0 0 1px var(--lp-primary);'
                    : 'border-color: var(--lp-border); background: var(--lp-field-bg); box-shadow: none;'"
                style="padding: 1rem; border: 1px solid var(--lp-border); border-radius: var(--lp-radius); cursor: pointer; transition: all 0.15s; text-align: center; font-family: inherit; display: flex; flex-direction: column; align-items: center; gap: 0.5rem; min-height: 80px; backdrop-filter: blur(4px);"
                onmouseover="if (!this.dataset.sel) { this.style.background='var(--lp-field-hover)'; }"
                onmouseout="if (!this.dataset.sel) { this.style.background='var(--lp-field-bg)'; }"
                x-effect="$el.dataset.sel = selected === '{{ $option['value'] }}' ? '1' : ''"
            >
                @if(isset($option['icon']))
                    <span style="font-size: 1.75rem; line-height: 1;">{{ $option['icon'] }}</span>
                @endif
                <span style="font-size: 0.875rem; font-weight: 500; color: var(--lp-text);">{{ $option['label'] }}</span>
            </button>
        @endforeach
    </div>
    @error("formData.{$key}")
        <p style="font-size: 0.75rem; margin-top: 0.375rem; color: var(--lp-error);">{{ $message }}</p>
    @enderror
</div>
