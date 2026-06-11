@php
    $total = max(1, array_sum($slices));
    $shades = [$color, $color . '99', $color . '40'];
    $offset = 25; // 12-Uhr-Start
    $segments = [];
    foreach (array_values($slices) as $i => $value) {
        $pct = $value / $total * 100;
        $segments[] = ['pct' => $pct, 'dash' => $pct, 'offset' => $offset, 'shade' => $shades[$i % 3]];
        $offset -= $pct;
    }
@endphp
<div class="flex items-center gap-6">
    <svg viewBox="0 0 36 36" class="h-32 w-32 -rotate-90">
        @foreach ($segments as $segment)
            <circle cx="18" cy="18" r="16" fill="none" stroke="{{ $segment['shade'] }}" stroke-width="4"
                    stroke-dasharray="{{ round($segment['dash'], 2) }} {{ round(100 - $segment['dash'], 2) }}"
                    stroke-dashoffset="{{ round($segment['offset'], 2) }}" pathLength="100"/>
        @endforeach
    </svg>
    <ul class="space-y-1 text-sm">
        @foreach ($slices as $key => $value)
            <li class="flex items-center gap-2">
                <span class="inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $shades[$loop->index % 3] }}"></span>
                <span>{{ $labels[$key] ?? $key }}</span>
                <span class="tabular-nums text-gray-500">{{ number_format($value, 0, ',', '.') }} ({{ number_format($value / $total * 100, 1, ',', '.') }}%)</span>
            </li>
        @endforeach
    </ul>
</div>
