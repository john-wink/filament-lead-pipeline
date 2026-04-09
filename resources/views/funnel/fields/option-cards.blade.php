<div style="margin-bottom: 0.25rem;" x-data="{ selected: @js($value ?? null) }">
    <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.75rem; color: var(--lp-text); opacity: 0.9;">
        {{ $field->definition->name }}
        @if($field->is_required) <span style="color: var(--lp-error);">*</span> @endif
    </label>
    <div style="display: flex; flex-direction: column; gap: 0.625rem;">
        @foreach($field->funnel_options ?? [] as $option)
            <button
                type="button"
                wire:click="$set('formData.{{ $key }}', '{{ $option['value'] }}')"
                x-on:click="selected = '{{ $option['value'] }}'"
                x-bind:class="selected === '{{ $option['value'] }}' ? 'lp-card-selected' : 'lp-card-default'"
                class="lp-option-card"
            >
                <span class="lp-radio" x-bind:class="selected === '{{ $option['value'] }}' ? 'lp-radio-on' : ''">
                    <svg x-show="selected === '{{ $option['value'] }}'" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" x-transition.opacity>
                        <path d="M20 6L9 17l-5-5"/>
                    </svg>
                </span>
                <span class="lp-card-label">{{ $option['label'] }}</span>
            </button>
        @endforeach
    </div>
    @error("formData.{$key}")
        <p style="font-size: 0.75rem; margin-top: 0.375rem; color: var(--lp-error);">{{ $message }}</p>
    @enderror

    <style>
        .lp-option-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            width: 100%;
            padding: 1rem 1.25rem;
            border: 1px solid var(--lp-border);
            border-radius: var(--lp-radius);
            background: var(--lp-field-bg);
            cursor: pointer;
            transition: all 0.15s ease;
            font-family: inherit;
            font-size: 1rem;
            font-weight: 500;
            color: var(--lp-text);
            text-align: left;
            backdrop-filter: blur(4px);
        }
        .lp-option-card:hover { background: var(--lp-field-hover); border-color: var(--lp-check-border); }
        .lp-card-selected { border-color: var(--lp-primary) !important; background: var(--lp-selected-bg) !important; box-shadow: 0 0 0 1px var(--lp-primary); }
        .lp-radio {
            width: 24px; height: 24px; min-width: 24px;
            border-radius: 50%;
            border: 2px solid var(--lp-check-border);
            display: inline-flex; align-items: center; justify-content: center;
            background: transparent;
            transition: all 0.15s ease;
        }
        .lp-radio-on { border-color: var(--lp-primary); background: var(--lp-primary); }
        .lp-card-label { flex: 1; }
    </style>
</div>
