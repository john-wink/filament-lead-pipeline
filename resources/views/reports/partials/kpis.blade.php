@php
    $tiles = [
        ['key' => 'inquiries', 'value' => number_format($data->inquiries, 0, ',', '.'), 'delta' => $data->deltas['inquiries'] ?? null],
        ['key' => 'cost_per_inquiry', 'value' => null !== $data->costPerInquiry ? number_format($data->costPerInquiry, 2, ',', '.') . ' €' : '–', 'delta' => $data->deltas['cost_per_inquiry'] ?? null],
        ['key' => 'spend', 'value' => number_format($data->spend, 2, ',', '.') . ' €', 'delta' => $data->deltas['spend'] ?? null],
        // Reach-Delta bewusst null: kein Vorzeitraum-Reach ohne zusätzlichen API-Call
        ['key' => 'reach', 'value' => null !== $data->reach ? number_format($data->reach, 0, ',', '.') : '–', 'delta' => null],
        ['key' => 'impressions', 'value' => number_format($data->impressions, 0, ',', '.'), 'delta' => $data->deltas['impressions'] ?? null],
        ['key' => 'link_clicks', 'value' => number_format($data->linkClicks, 0, ',', '.'), 'delta' => $data->deltas['link_clicks'] ?? null],
    ];
@endphp
<section class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
    @foreach ($tiles as $tile)
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('lead-pipeline::reports.kpis.' . $tile['key']) }}</p>
            <p class="mt-1 text-xl font-semibold tabular-nums">{{ $tile['value'] }}</p>
            @if (null !== $tile['delta'])
                <p class="mt-0.5 text-xs tabular-nums {{ $tile['delta'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                    {{ $tile['delta'] >= 0 ? '▲' : '▼' }} {{ number_format(abs($tile['delta']), 1, ',', '.') }}%
                </p>
            @endif
        </div>
    @endforeach
</section>
