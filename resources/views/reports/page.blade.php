<div class="min-h-screen" style="--report-accent: {{ $branding->accentColor }}">
    @unless ($unlocked)
        @include('lead-pipeline::reports.partials.password-gate')
    @else
        @include('lead-pipeline::reports.partials.header')

        <main class="mx-auto max-w-6xl space-y-8 px-4 py-8">
            @foreach ($report->enabledSections() as $section)
                @includeIf('lead-pipeline::reports.partials.' . $section)
            @endforeach
        </main>

        @include('lead-pipeline::reports.partials.footer')
    @endunless

    <style media="print">
        main { max-width: 48rem !important; }
        header { position: static !important; }
        header [x-data], .report-pdf-button { display: none !important; }
        section { break-inside: avoid; }
    </style>
</div>
