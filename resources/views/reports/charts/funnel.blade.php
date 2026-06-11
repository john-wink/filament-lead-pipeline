@php $max = max(1, ...array_column($stages, 'value')); @endphp
<div class="space-y-2">
    @foreach ($stages as $stage)
        <div class="flex items-center gap-3 text-sm">
            <span class="w-28 shrink-0 text-gray-600">{{ $stage['label'] }}</span>
            <div class="h-7 rounded-md text-right" style="background: {{ $color }}; opacity: {{ 0.35 + 0.65 * (($loop->remaining + 1) / count($stages)) }}; width: {{ max(4, round($stage['value'] / $max * 100)) }}%">
                <span class="px-2 text-xs font-medium leading-7 text-white">{{ number_format($stage['value'], 0, ',', '.') }}</span>
            </div>
            @if (null !== $stage['cost_per'])
                <span class="shrink-0 text-xs tabular-nums text-gray-500">{{ number_format($stage['cost_per'], 2, ',', '.') }} €</span>
            @endif
        </div>
    @endforeach
</div>
