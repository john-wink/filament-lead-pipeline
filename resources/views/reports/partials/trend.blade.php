<section class="rounded-xl bg-white p-4 shadow-sm" x-data="{ tab: 'inquiries' }">
    <div class="mb-3 flex items-center gap-3 text-sm">
        <button type="button" @click="tab = 'inquiries'" :class="tab === 'inquiries' ? 'font-semibold' : 'text-gray-500'">
            {{ __('lead-pipeline::reports.kpis.inquiries') }}
        </button>
        <button type="button" @click="tab = 'link_clicks'" :class="tab === 'link_clicks' ? 'font-semibold' : 'text-gray-500'">
            {{ __('lead-pipeline::reports.kpis.link_clicks') }}
        </button>
    </div>
    <div x-show="tab === 'inquiries'">
        @include('lead-pipeline::reports.charts.area', [
            'series' => collect($trend)->map(fn (array $d): array => ['date' => $d['date'], 'value' => $d['inquiries']])->all(),
            'color'  => $branding->accentColor,
        ])
    </div>
    <div x-show="tab === 'link_clicks'" x-cloak>
        @include('lead-pipeline::reports.charts.area', [
            'series' => collect($trend)->map(fn (array $d): array => ['date' => $d['date'], 'value' => $d['link_clicks']])->all(),
            'color'  => $branding->accentColor,
        ])
    </div>
</section>
