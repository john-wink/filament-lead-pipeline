<div x-data="{
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
}">
    @if($isOpen)
    <div class="fixed inset-0 z-50 flex justify-end" x-init="loadChartJs()" x-transition>
        {{-- Backdrop --}}
        <div class="absolute inset-0 bg-black/50" wire:click="close"></div>

        {{-- Slideover Panel --}}
        <div class="relative w-full max-w-5xl bg-white dark:bg-gray-900 shadow-xl overflow-y-auto" wire:init="loadData">
            {{-- Header --}}
            <div class="sticky top-0 z-10 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 px-6 py-4">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        {{ $boardId ? __('lead-pipeline::lead-pipeline.analytics.title_board') : __('lead-pipeline::lead-pipeline.analytics.title_all') }}
                    </h2>
                    <div class="flex items-center gap-2">
                        @if($initialized)
                            <div x-data="{ exportOpen: false }" class="relative">
                                <button @click="exportOpen = !exportOpen" type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700 transition-colors">
                                    <x-heroicon-o-arrow-down-tray class="w-3.5 h-3.5" />
                                    {{ __('lead-pipeline::lead-pipeline.analytics.csv_export') }}
                                    <x-heroicon-m-chevron-down class="w-3 h-3" />
                                </button>
                                <div x-show="exportOpen" @click.outside="exportOpen = false" x-cloak x-transition
                                    class="absolute right-0 top-full mt-1 z-30 w-56 rounded-lg bg-white border border-gray-200 shadow-lg dark:bg-gray-800 dark:border-gray-700 py-1">
                                    <a href="{{ $this->getExportUrl('all') }}" class="flex items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700" @click="exportOpen = false">
                                        <x-heroicon-o-table-cells class="w-3.5 h-3.5" /> {{ __('lead-pipeline::lead-pipeline.analytics.export_all') }}
                                    </a>
                                    <a href="{{ $this->getExportUrl('berater') }}" class="flex items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700" @click="exportOpen = false">
                                        <x-heroicon-o-users class="w-3.5 h-3.5" /> {{ __('lead-pipeline::lead-pipeline.analytics.export_berater') }}
                                    </a>
                                    <a href="{{ $this->getExportUrl('matrix') }}" class="flex items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700" @click="exportOpen = false">
                                        <x-heroicon-o-view-columns class="w-3.5 h-3.5" /> {{ __('lead-pipeline::lead-pipeline.analytics.export_matrix') }}
                                    </a>
                                    <a href="{{ $this->getExportUrl('sources') }}" class="flex items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700" @click="exportOpen = false">
                                        <x-heroicon-o-bolt class="w-3.5 h-3.5" /> {{ __('lead-pipeline::lead-pipeline.analytics.export_sources') }}
                                    </a>
                                </div>
                            </div>
                        @endif
                        <button wire:click="close" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                            <x-heroicon-o-x-mark class="w-5 h-5" />
                        </button>
                    </div>
                </div>

                {{-- Time range selector --}}
                <div class="flex items-center gap-2 flex-wrap">
                    @foreach(['today' => __('lead-pipeline::lead-pipeline.analytics.today'), '7' => __('lead-pipeline::lead-pipeline.analytics.days_7'), '30' => __('lead-pipeline::lead-pipeline.analytics.days_30'), '90' => __('lead-pipeline::lead-pipeline.analytics.days_90'), 'all' => __('lead-pipeline::lead-pipeline.analytics.all')] as $value => $label)
                        <button
                            wire:click="$set('preset', '{{ $value }}')"
                            @class([
                                'px-2.5 py-1 rounded-md text-xs font-medium transition-colors',
                                'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-400' => $preset === $value,
                                'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800' => $preset !== $value,
                            ])
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                    <span class="mx-1 h-4 w-px bg-gray-300 dark:bg-gray-600"></span>
                    <input type="date" wire:model.live="dateFrom" placeholder="{{ __('lead-pipeline::lead-pipeline.analytics.from') }}"
                        class="rounded-md border-gray-300 text-xs px-2 py-1 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-300" />
                    <span class="text-xs text-gray-400">—</span>
                    <input type="date" wire:model.live="dateTo" placeholder="{{ __('lead-pipeline::lead-pipeline.analytics.to') }}"
                        class="rounded-md border-gray-300 text-xs px-2 py-1 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-300" />
                </div>
            </div>

            {{-- Content --}}
            <div class="px-6 py-4 space-y-6">
                @if(!$initialized)
                    {{-- Skeleton Loaders --}}
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                        @for($i = 0; $i < 6; $i++)
                            <div class="animate-pulse bg-gray-100 dark:bg-gray-800 rounded-lg h-20"></div>
                        @endfor
                    </div>
                    <div class="animate-pulse bg-gray-100 dark:bg-gray-800 rounded-lg h-48"></div>
                    <div class="animate-pulse bg-gray-100 dark:bg-gray-800 rounded-lg h-64"></div>
                @else
                    {{-- Section 1: KPIs --}}
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                        @foreach([
                            ['label' => __('lead-pipeline::lead-pipeline.analytics.kpi_total'), 'value' => number_format($kpis['total'] ?? 0), 'icon' => 'heroicon-o-users', 'color' => 'gray'],
                            ['label' => __('lead-pipeline::lead-pipeline.analytics.kpi_new'), 'value' => number_format($kpis['new'] ?? 0), 'icon' => 'heroicon-o-plus-circle', 'color' => 'blue'],
                            ['label' => __('lead-pipeline::lead-pipeline.analytics.kpi_won'), 'value' => number_format($kpis['won'] ?? 0), 'icon' => 'heroicon-o-check-circle', 'color' => 'emerald'],
                            ['label' => __('lead-pipeline::lead-pipeline.analytics.kpi_lost'), 'value' => number_format($kpis['lost'] ?? 0), 'icon' => 'heroicon-o-x-circle', 'color' => 'red'],
                            ['label' => __('lead-pipeline::lead-pipeline.analytics.kpi_conversion'), 'value' => ($kpis['conversion_rate'] ?? 0) . '%', 'icon' => 'heroicon-o-arrow-trending-up', 'color' => 'amber'],
                            ['label' => __('lead-pipeline::lead-pipeline.analytics.kpi_avg_value'), 'value' => number_format($kpis['avg_value'] ?? 0, 0, ',', '.') . ' €', 'icon' => 'heroicon-o-currency-euro', 'color' => 'emerald'],
                        ] as $kpi)
                            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 bg-white dark:bg-gray-800">
                                <div class="flex items-center gap-2 mb-1">
                                    <x-dynamic-component :component="$kpi['icon']" class="w-4 h-4 text-{{ $kpi['color'] }}-500" />
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $kpi['label'] }}</span>
                                </div>
                                <span class="text-lg font-bold text-gray-900 dark:text-white">{{ $kpi['value'] }}</span>
                            </div>
                        @endforeach
                    </div>

                    {{-- Trend Chart --}}
                    @if(!empty($trendData['labels']))
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-800">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ __('lead-pipeline::lead-pipeline.analytics.trend') }}</h3>
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
                                                    type: 'line',
                                                    data: {
                                                        labels: @js($trendData['labels']),
                                                        datasets: [
                                                            { label: @js(__('lead-pipeline::lead-pipeline.analytics.chart_total')), data: @js($trendData['total']), borderColor: '#3B82F6', backgroundColor: 'rgba(59,130,246,0.1)', fill: true, tension: 0.3 },
                                                            { label: @js(__('lead-pipeline::lead-pipeline.analytics.chart_won')), data: @js($trendData['won']), borderColor: '#10B981', backgroundColor: 'transparent', tension: 0.3 },
                                                            { label: @js(__('lead-pipeline::lead-pipeline.analytics.chart_lost')), data: @js($trendData['lost']), borderColor: '#EF4444', backgroundColor: 'transparent', tension: 0.3 },
                                                        ]
                                                    },
                                                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
                                                });
                                            };
                                            tryInit();
                                        }
                                    }"
                                ></canvas>
                            </div>
                        </div>
                    @endif

                    {{-- Section 2: Berater Matrix --}}
                    @foreach($matrixData as $boardMatrix)
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 overflow-hidden">
                            @if(count($matrixData) > 1)
                                <div class="px-4 py-2 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $boardMatrix['board'] }}</h3>
                                </div>
                            @else
                                <div class="px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('lead-pipeline::lead-pipeline.analytics.matrix_title') }}</h3>
                                </div>
                            @endif
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs">
                                    <thead>
                                        <tr class="bg-gray-50 dark:bg-gray-800/50">
                                            <th class="text-left px-3 py-2 font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.analytics.matrix_advisor') }}</th>
                                            @foreach($boardMatrix['phases'] as $phase)
                                                <th class="text-center px-2 py-2 font-medium text-gray-600 dark:text-gray-400">
                                                    <span class="inline-block w-2 h-2 rounded-full mr-1" style="background: {{ $phase['color'] }}"></span>
                                                    {{ $phase['name'] }}
                                                </th>
                                            @endforeach
                                            <th class="text-center px-3 py-2 font-semibold text-gray-900 dark:text-white">{{ __('lead-pipeline::lead-pipeline.analytics.matrix_total') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                        @foreach($boardMatrix['rows'] as $row)
                                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30">
                                                <td class="px-3 py-2 text-gray-900 dark:text-white font-medium">{{ $row['berater'] }}</td>
                                                @foreach($boardMatrix['phases'] as $phase)
                                                    <td class="text-center px-2 py-2 text-gray-600 dark:text-gray-400">
                                                        {{ $row['phases'][$phase['id']] ?? 0 }}
                                                    </td>
                                                @endforeach
                                                <td class="text-center px-3 py-2 font-semibold text-gray-900 dark:text-white">{{ $row['total'] }}</td>
                                            </tr>
                                        @endforeach
                                        {{-- Totals --}}
                                        <tr class="bg-gray-50 dark:bg-gray-800/50 font-semibold">
                                            <td class="px-3 py-2 text-gray-900 dark:text-white">{{ __('lead-pipeline::lead-pipeline.analytics.matrix_total') }}</td>
                                            @foreach($boardMatrix['phases'] as $phase)
                                                <td class="text-center px-2 py-2 text-gray-900 dark:text-white">
                                                    {{ $boardMatrix['totals']['phases'][$phase['id']] ?? 0 }}
                                                </td>
                                            @endforeach
                                            <td class="text-center px-3 py-2 text-gray-900 dark:text-white">{{ $boardMatrix['totals']['total'] }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach

                    {{-- Berater Bar Chart --}}
                    @if(!empty($beraterChartData['labels']))
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-800">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ __('lead-pipeline::lead-pipeline.analytics.chart_per_advisor') }}</h3>
                            <div style="height: {{ max(150, count($beraterChartData['labels']) * 35) }}px;">
                                <canvas
                                    x-data="{
                                        chart: null,
                                        init() {
                                            const el = this.$el;
                                            const tryInit = () => {
                                                if (typeof Chart === 'undefined') { setTimeout(tryInit, 100); return; }
                                                    if (this.chart) { this.chart.destroy(); }
                                                this.chart = new Chart(el, {
                                                type: 'bar',
                                                data: {
                                                    labels: @js($beraterChartData['labels']),
                                                    datasets: [
                                                        { label: @js(__('lead-pipeline::lead-pipeline.analytics.chart_open')), data: @js($beraterChartData['open']), backgroundColor: '#6B7280' },
                                                        { label: @js(__('lead-pipeline::lead-pipeline.analytics.chart_in_progress')), data: @js($beraterChartData['inProgress']), backgroundColor: '#3B82F6' },
                                                        { label: @js(__('lead-pipeline::lead-pipeline.analytics.chart_won')), data: @js($beraterChartData['won']), backgroundColor: '#10B981' },
                                                        { label: @js(__('lead-pipeline::lead-pipeline.analytics.chart_lost')), data: @js($beraterChartData['lost']), backgroundColor: '#EF4444' },
                                                    ]
                                                },
                                                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }, scales: { x: { stacked: true, ticks: { precision: 0 } }, y: { stacked: true } } }
                                                });
                                            };
                                            tryInit();
                                        }
                                    }"
                                ></canvas>
                            </div>
                        </div>
                    @endif

                    {{-- Section 3: Sources Performance --}}
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <div class="lg:col-span-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 overflow-hidden">
                            <div class="px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('lead-pipeline::lead-pipeline.analytics.sources_title') }}</h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs">
                                    <thead>
                                        <tr class="bg-gray-50 dark:bg-gray-800/50">
                                            <th class="text-left px-3 py-2 font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.analytics.sources_source') }}</th>
                                            <th class="text-center px-2 py-2 font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.analytics.sources_leads') }}</th>
                                            <th class="text-center px-2 py-2 font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.analytics.sources_won') }}</th>
                                            <th class="text-center px-2 py-2 font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.analytics.sources_lost') }}</th>
                                            <th class="text-center px-2 py-2 font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.analytics.sources_conversion') }}</th>
                                            <th class="text-center px-3 py-2 font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.analytics.sources_avg_value') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                        @forelse($sourcesData as $source)
                                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30">
                                                <td class="px-3 py-2 text-gray-900 dark:text-white font-medium">{{ $source['source'] }}</td>
                                                <td class="text-center px-2 py-2 text-gray-600 dark:text-gray-400">{{ $source['total'] }}</td>
                                                <td class="text-center px-2 py-2 text-emerald-600 dark:text-emerald-400">{{ $source['won'] }}</td>
                                                <td class="text-center px-2 py-2 text-red-600 dark:text-red-400">{{ $source['lost'] }}</td>
                                                <td class="text-center px-2 py-2 text-gray-600 dark:text-gray-400">{{ $source['conversion'] }}%</td>
                                                <td class="text-center px-3 py-2 text-gray-600 dark:text-gray-400">{{ number_format($source['avg_value'], 0, ',', '.') }} €</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="6" class="px-3 py-4 text-center text-gray-400">{{ __('lead-pipeline::lead-pipeline.analytics.no_data') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {{-- Donut Chart --}}
                        @if(!empty($sourcesChartData['labels']))
                            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-800">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ __('lead-pipeline::lead-pipeline.analytics.chart_per_source') }}</h3>
                                <div style="height: 200px;">
                                    <canvas
                                        x-data="{
                                            chart: null,
                                            init() {
                                                const el = this.$el;
                                                const colors = ['#3B82F6','#10B981','#F59E0B','#EF4444','#8B5CF6','#EC4899','#6B7280','#F97316'];
                                                const tryInit = () => {
                                                    if (typeof Chart === 'undefined') { setTimeout(tryInit, 100); return; }
                                                    if (this.chart) { this.chart.destroy(); }
                                                    this.chart = new Chart(el, {
                                                        type: 'doughnut',
                                                        data: {
                                                            labels: @js($sourcesChartData['labels']),
                                                            datasets: [{ data: @js($sourcesChartData['data']), backgroundColor: colors.slice(0, @js(count($sourcesChartData['labels']))) }]
                                                        },
                                                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } } }
                                                    });
                                                };
                                                tryInit();
                                            }
                                        }"
                                    ></canvas>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
