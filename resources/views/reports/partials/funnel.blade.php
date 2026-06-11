@if (collect($funnel)->sum('value') > 0)
    <section class="rounded-xl bg-white p-4 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('lead-pipeline::reports.sections.funnel') }}</h2>
        @include('lead-pipeline::reports.charts.funnel', ['stages' => $funnel, 'color' => $branding->accentColor])
    </section>
@endif
