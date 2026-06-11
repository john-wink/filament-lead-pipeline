@php
    $values = array_column($series, 'value');
    $max = max(1, ...($values ?: [0]));
    $count = max(1, count($series) - 1);
    $points = collect($series)->values()->map(
        fn (array $point, int $i): string => round($i / $count * 580 + 10, 1) . ',' . round(150 - ($point['value'] / $max * 130), 1)
    );
    $area = '10,150 ' . $points->implode(' ') . ' 590,150';
@endphp
<svg viewBox="0 0 600 160" class="h-40 w-full" role="img" preserveAspectRatio="none">
    <polygon points="{{ $area }}" fill="{{ $color }}" opacity="0.12"/>
    <polyline points="{{ $points->implode(' ') }}" fill="none" stroke="{{ $color }}" stroke-width="2"/>
</svg>
