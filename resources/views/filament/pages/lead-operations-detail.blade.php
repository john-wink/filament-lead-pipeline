<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2"
    x-data="{
        chartJsLoaded: false,
        loadChartJs() {
            if (this.chartJsLoaded || typeof Chart !== 'undefined') {
                this.chartJsLoaded = true;
                return Promise.resolve();
            }
            return new Promise((resolve) => {
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';
                script.onload = () => { this.chartJsLoaded = true; resolve(); };
                document.head.appendChild(script);
            });
        }
    }"
    x-init="loadChartJs()"
>
    <x-filament::section>
        <x-slot name="heading">{{ __('lead-pipeline::lead-pipeline.operations.speed_to_lead') }}</x-slot>
        <div style="height: 200px;">
            <canvas
                x-data="{
                    chart: null,
                    init() {
                        const el = this.$el;
                        const tryInit = () => {
                            if (typeof Chart === 'undefined') { setTimeout(tryInit, 100); return; }
                            if (this.chart) { this.chart.destroy(); }
                            this.chart = new Chart(el, {
                                type: 'doughnut',
                                data: {
                                    labels: ['< 1 Std', '1–24 Std', '24–48 Std', '> 48 Std'],
                                    datasets: [{ data: @js(array_values($response['buckets'])), backgroundColor: ['#1f9d57', '#3b82f6', '#e0a04a', '#ef4444'] }],
                                },
                                options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } } },
                            });
                        };
                        tryInit();
                    }
                }"
            ></canvas>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('lead-pipeline::lead-pipeline.operations.loss_reasons') }}</x-slot>
        <div style="height: 200px;">
            <canvas
                x-data="{
                    chart: null,
                    init() {
                        const el = this.$el;
                        const tryInit = () => {
                            if (typeof Chart === 'undefined') { setTimeout(tryInit, 100); return; }
                            if (this.chart) { this.chart.destroy(); }
                            this.chart = new Chart(el, {
                                type: 'doughnut',
                                data: {
                                    labels: @js(collect($lossReasons)->pluck('reason')),
                                    datasets: [{ data: @js(collect($lossReasons)->pluck('count')), backgroundColor: ['#ef4444', '#e0a04a', '#3b82f6', '#8a949f', '#a855f7'] }],
                                },
                                options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } } },
                            });
                        };
                        tryInit();
                    }
                }"
            ></canvas>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('lead-pipeline::lead-pipeline.operations.velocity') }}</x-slot>
        <div class="text-2xl font-bold tabular-nums">{{ number_format($velocity['velocity'], 2, ',', '.') }} €/Tag</div>
        <div class="mt-2 text-sm text-gray-500">
            {{ $velocity['open'] }} offen &middot; {{ number_format($velocity['win_rate'], 1, ',', '.') }} % Win &middot; &Oslash; {{ number_format($velocity['cycle_days'], 1, ',', '.') }} Tage
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('lead-pipeline::lead-pipeline.operations.stage_dwell') }}</x-slot>
        <table class="w-full text-sm tabular-nums">
            <thead>
                <tr class="text-left text-gray-500">
                    <th>{{ __('lead-pipeline::lead-pipeline.phase.singular') }}</th>
                    <th>&Oslash; Tage</th>
                    <th class="text-right">% &uuml;beraltert</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($stageDwell as $stage)
                    <tr class="border-t border-gray-100 dark:border-gray-700">
                        <td class="py-1">{{ $stage['label'] }}</td>
                        <td>{{ number_format($stage['avg_days'], 1, ',', '.') }}</td>
                        <td class="text-right">{{ number_format($stage['overaged_pct'], 1, ',', '.') }} %</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section class="lg:col-span-2">
        <x-slot name="heading">{{ __('lead-pipeline::lead-pipeline.operations.heatmap') }}</x-slot>
        <div class="overflow-x-auto">
            <table class="text-xs tabular-nums">
                <thead>
                    <tr>
                        <th></th>
                        @foreach ($heatmap['slots'] as $slot)
                            <th class="px-2">{{ $slot }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($heatmap['days'] as $d => $day)
                        <tr>
                            <td class="pr-2 font-medium">{{ $day }}</td>
                            @foreach ($heatmap['matrix'][$d] as $count)
                                <td class="p-1"><span class="inline-block h-5 w-5 rounded-sm text-center leading-5" style="background-color: rgba(31,157,87,{{ $count > 0 ? min(0.15 + $count * 0.2, 1) : 0.05 }})">{{ $count ?: '' }}</span></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section class="lg:col-span-2">
        <x-slot name="heading">{{ __('lead-pipeline::lead-pipeline.operations.source_economics') }}</x-slot>
        <table class="w-full text-sm tabular-nums">
            <thead>
                <tr class="text-left text-gray-500">
                    <th>{{ __('lead-pipeline::lead-pipeline.analytics.sources_source') }}</th>
                    <th>{{ __('lead-pipeline::lead-pipeline.analytics.sources_leads') }}</th>
                    <th>{{ __('lead-pipeline::lead-pipeline.analytics.sources_won') }}</th>
                    <th>{{ __('lead-pipeline::lead-pipeline.analytics.sources_conversion') }}</th>
                    <th>{{ __('lead-pipeline::lead-pipeline.operations.cost_per_lead') }}</th>
                    <th>{{ __('lead-pipeline::lead-pipeline.operations.cost_per_acquisition') }}</th>
                    <th class="text-right">&Oslash; Wert</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sources as $src)
                    <tr class="border-t border-gray-100 dark:border-gray-700">
                        <td class="py-1">{{ $src['source'] }}</td>
                        <td>{{ $src['leads'] }}</td>
                        <td>{{ $src['won'] }}</td>
                        <td>{{ number_format($src['conversion'], 1, ',', '.') }} %</td>
                        <td>{{ null === $src['cost_per_lead'] ? '–' : number_format($src['cost_per_lead'], 2, ',', '.') . ' €' }}</td>
                        <td>{{ null === $src['cost_per_acquisition'] ? '–' : number_format($src['cost_per_acquisition'], 2, ',', '.') . ' €' }}</td>
                        <td class="text-right">{{ number_format($src['avg_value'], 2, ',', '.') }} €</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    @if (! empty($funnel))
        <x-filament::section class="lg:col-span-2">
            <x-slot name="heading">{{ __('lead-pipeline::lead-pipeline.operations.funnel') }}</x-slot>
            <table class="w-full text-sm tabular-nums">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th>{{ __('lead-pipeline::lead-pipeline.phase.singular') }}</th>
                        <th>Anzahl</th>
                        <th class="text-right">Drop %</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($funnel as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-700">
                            <td class="py-1">{{ $row['label'] }}</td>
                            <td>{{ $row['count'] }}</td>
                            <td class="text-right">{{ number_format($row['drop_pct'], 1, ',', '.') }} %</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif

    <x-filament::section class="lg:col-span-2">
        <x-slot name="heading">{{ __('lead-pipeline::lead-pipeline.operations.ops_ranking') }}</x-slot>
        <table class="w-full text-sm tabular-nums">
            <thead>
                <tr class="text-left text-gray-500">
                    <th>#</th>
                    <th>{{ __('lead-pipeline::lead-pipeline.analytics.matrix_advisor') }}</th>
                    <th>{{ __('lead-pipeline::lead-pipeline.operations.ops_score') }}</th>
                    <th>SLA %</th>
                    <th>&Oslash; Reaktion</th>
                    <th class="text-right">{{ __('lead-pipeline::lead-pipeline.analytics.kpi_won') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ranking as $i => $row)
                    <tr class="border-t border-gray-100 dark:border-gray-700">
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $row['advisor_id'] ?? '—' }}</td>
                        <td class="font-semibold">{{ number_format($row['ops_score'], 1, ',', '.') }}</td>
                        <td>{{ number_format($row['sla_pct'], 1, ',', '.') }} %</td>
                        <td>{{ $row['avg_response_minutes'] !== null ? number_format($row['avg_response_minutes'], 1, ',', '.') . ' min' : '–' }}</td>
                        <td class="text-right">{{ $row['won'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</div>
