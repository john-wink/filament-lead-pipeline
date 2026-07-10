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
            {{-- wire:key varies with the range/board so Livewire replaces (not morphs) the canvas on
                 every filter change: a morphed <canvas> that Chart.js has drawn on goes blank and never
                 re-inits, because Alpine's init() only runs on a freshly inserted node. --}}
            <canvas
                wire:key="ops-speed-chart-{{ $preset }}-{{ $boardId }}-{{ $dateFrom }}-{{ $dateTo }}"
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
                    },
                    destroy() { if (this.chart) { this.chart.destroy(); } }
                }"
            ></canvas>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('lead-pipeline::lead-pipeline.operations.loss_reasons') }}</x-slot>
        <div style="height: 200px;">
            <canvas
                wire:key="ops-loss-chart-{{ $preset }}-{{ $boardId }}-{{ $dateFrom }}-{{ $dateTo }}"
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
                    },
                    destroy() { if (this.chart) { this.chart.destroy(); } }
                }"
            ></canvas>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('lead-pipeline::lead-pipeline.operations.velocity') }}<span class="ms-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-500 dark:bg-gray-800">{{ __('lead-pipeline::lead-pipeline.operations.as_of_today') }}</span></x-slot>
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
        <x-slot name="heading">{{ __('lead-pipeline::lead-pipeline.operations.matrix_title') }}</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm tabular-nums">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="py-1">{{ __('lead-pipeline::lead-pipeline.operations.matrix_advisor') }}</th>
                        <th>{{ __('lead-pipeline::lead-pipeline.operations.calls') }}</th>
                        <th>{{ __('lead-pipeline::lead-pipeline.operations.emails') }}</th>
                        <th>{{ __('lead-pipeline::lead-pipeline.operations.notes') }}</th>
                        <th>{{ __('lead-pipeline::lead-pipeline.operations.moves') }}</th>
                        <th>{{ __('lead-pipeline::lead-pipeline.operations.first_contacts') }}</th>
                        <th>{{ __('lead-pipeline::lead-pipeline.operations.assigned_new') }}</th>
                        <th>{{ __('lead-pipeline::lead-pipeline.operations.won') }}</th>
                        <th>{{ __('lead-pipeline::lead-pipeline.operations.lost') }}</th>
                        <th>{{ __('lead-pipeline::lead-pipeline.operations.conversion') }}</th>
                        <th>{{ __('lead-pipeline::lead-pipeline.operations.avg_response') }}</th>
                        <th>SLA %</th>
                        <th>{{ __('lead-pipeline::lead-pipeline.operations.activities_per_lead') }}</th>
                        <th class="text-right">{{ __('lead-pipeline::lead-pipeline.operations.score') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($matrix['rows'] as $row)
                        <tr wire:key="matrix-row-{{ $row['advisor_id'] }}"
                            x-on:click="$dispatch('open-advisor-scorecard', { advisorId: '{{ $row['advisor_id'] }}' })"
                            class="cursor-pointer border-t border-gray-100 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800"
                            title="{{ __('lead-pipeline::lead-pipeline.operations.open_scorecard') }}">
                            <td class="py-1 font-medium">{{ $row['advisor_name'] }}</td>
                            <td>{{ $row['calls'] }}</td>
                            <td>{{ $row['emails'] }}</td>
                            <td>{{ $row['notes'] }}</td>
                            <td>{{ $row['moves'] }}</td>
                            <td>{{ $row['first_contacts'] }}</td>
                            <td>{{ $row['assigned_new'] }}</td>
                            <td>
                                {{ $row['won'] }}
                                @if (null !== $row['delta_won'] && 0 !== $row['delta_won'])
                                    <span class="text-xs {{ $row['delta_won'] > 0 ? 'text-primary-600' : 'text-red-600' }}">{{ $row['delta_won'] > 0 ? '▲' : '▼' }}{{ abs($row['delta_won']) }}</span>
                                @endif
                            </td>
                            <td>{{ $row['lost'] }}</td>
                            <td>{{ number_format($row['conversion'], 1, ',', '.') }} %</td>
                            <td>{{ $row['avg_response_minutes'] !== null ? number_format($row['avg_response_minutes'], 1, ',', '.') . ' min' : '–' }}</td>
                            <td>{{ number_format($row['sla_pct'], 1, ',', '.') }} %</td>
                            <td>{{ $row['activities_per_lead'] !== null ? number_format($row['activities_per_lead'], 2, ',', '.') : '–' }}</td>
                            <td class="text-right">
                                <span class="rounded-full bg-primary-50 px-2 py-0.5 font-semibold text-primary-700 dark:bg-primary-950 dark:text-primary-300">{{ number_format($row['scores']['total'], 1, ',', '.') }}</span>
                                @if (null !== $row['delta_score'] && 0.0 !== $row['delta_score'])
                                    <span class="text-xs {{ $row['delta_score'] > 0 ? 'text-primary-600' : 'text-red-600' }}">{{ $row['delta_score'] > 0 ? '▲' : '▼' }}{{ number_format(abs($row['delta_score']), 1, ',', '.') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-200 font-semibold dark:border-gray-600">
                        <td class="py-1">{{ __('lead-pipeline::lead-pipeline.operations.team_row') }}</td>
                        <td>{{ $matrix['team']['calls'] }}</td>
                        <td>{{ $matrix['team']['emails'] }}</td>
                        <td>{{ $matrix['team']['notes'] }}</td>
                        <td>{{ $matrix['team']['moves'] }}</td>
                        <td>{{ $matrix['team']['first_contacts'] }}</td>
                        <td>{{ $matrix['team']['assigned_new'] }}</td>
                        <td>{{ $matrix['team']['won'] }}</td>
                        <td>{{ $matrix['team']['lost'] }}</td>
                        <td>{{ number_format($matrix['team']['conversion'], 1, ',', '.') }} %</td>
                        <td>{{ $matrix['team']['avg_response_minutes'] !== null ? number_format($matrix['team']['avg_response_minutes'], 1, ',', '.') . ' min' : '–' }}</td>
                        <td>{{ number_format($matrix['team']['sla_pct'], 1, ',', '.') }} %</td>
                        <td>{{ $matrix['team']['activities_per_lead'] !== null ? number_format($matrix['team']['activities_per_lead'], 2, ',', '.') : '–' }}</td>
                        <td class="text-right">{{ number_format($matrix['team']['score_avg'], 1, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>
</div>
