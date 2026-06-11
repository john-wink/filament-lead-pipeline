@if (null !== $gender)
    <section class="rounded-xl bg-white p-4 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('lead-pipeline::reports.sections.gender') }}</h2>
        @include('lead-pipeline::reports.charts.pie', [
            'slices' => $gender,
            'labels' => [
                'male'    => __('lead-pipeline::reports.gender.male'),
                'female'  => __('lead-pipeline::reports.gender.female'),
                'unknown' => __('lead-pipeline::reports.gender.unknown'),
            ],
            'color'  => $branding->accentColor,
        ])
    </section>
@endif
