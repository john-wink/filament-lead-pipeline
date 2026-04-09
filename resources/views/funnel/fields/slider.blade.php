@php
    $options = $field->funnel_options ?? [];
    $min = $options['min'] ?? 0;
    $max = $options['max'] ?? 100;
    $step = $options['step'] ?? 1;
    $unit = $options['unit'] ?? '';
    $initial = $value ?? $min;
@endphp
@php $entangleKey = 'formData.' . $key; @endphp
<div
    style="margin-bottom: 0.25rem;"
    x-data="{ val: {{ $initial }} }"
    x-init="
        if ($wire.get('{{ $entangleKey }}') === null || $wire.get('{{ $entangleKey }}') === '') {
            $wire.set('{{ $entangleKey }}', {{ $initial }});
        }
        $watch('val', v => $wire.set('{{ $entangleKey }}', Number(v)));
    "
>
    <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: var(--lp-text); opacity: 0.9;">
        {{ $field->definition->name }}
        @if($field->is_required) <span style="color: var(--lp-error);">*</span> @endif
    </label>
    <div style="text-align: center; margin-bottom: 1rem;">
        <span x-text="Number(val).toLocaleString('de-DE')" style="font-size: 2rem; font-weight: 700; color: var(--lp-primary);"></span>
        @if($unit) <span style="font-size: 1.25rem; font-weight: 600; color: var(--lp-primary); margin-left: 0.25rem;">{{ $unit }}</span> @endif
    </div>
    <input
        type="range"
        x-model="val"
        min="{{ $min }}"
        max="{{ $max }}"
        step="{{ $step }}"
        style="width: 100%; height: 0.5rem; border-radius: 9999px; appearance: none; cursor: pointer; accent-color: var(--lp-primary); outline: none; background: var(--lp-progress-bg);"
    >
    <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-top: 0.5rem; color: var(--lp-subtle);">
        <span>{{ number_format($min, 0, ',', '.') }}{{ $unit ? ' '.$unit : '' }}</span>
        <span>{{ number_format($max, 0, ',', '.') }}{{ $unit ? ' '.$unit : '' }}</span>
    </div>
    @error("formData.{$key}")
        <p style="font-size: 0.75rem; margin-top: 0.375rem; color: var(--lp-error);">{{ $message }}</p>
    @enderror
</div>
