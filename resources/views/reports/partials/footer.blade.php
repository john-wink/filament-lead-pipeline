<footer class="mx-auto max-w-6xl px-4 py-6 text-xs text-gray-500">
    <div class="flex items-center justify-between gap-4">
        <span>{{ $branding->footerText }}</span>
        <span class="flex items-center gap-3">
            @if ($branding->contact)<span>{{ $branding->contact }}</span>@endif
            @if ($branding->imprintUrl)<a href="{{ $branding->imprintUrl }}" class="underline">{{ __('lead-pipeline::reports.imprint') }}</a>@endif
            @if ($syncedAt) <span>{{ __('lead-pipeline::reports.synced_at', ['date' => $syncedAt->format('d.m.Y H:i')]) }}</span> @endif
            <a href="{{ route('lead-pipeline.reports.pdf', ['token' => $report->share_token, 'zeitraum' => $range->preset->value]) }}"
               class="report-pdf-button rounded-lg border border-gray-300 px-3 py-1.5 font-medium text-gray-700">
                {{ __('lead-pipeline::reports.download_pdf') }}
            </a>
            <span class="report-qr inline-block h-10 w-10 [&_svg]:h-full [&_svg]:w-full" title="{{ route('lead-pipeline.reports.show', $report->share_token) }}">
                {!! JohnWink\FilamentLeadPipeline\Support\QrCodeSvg::make(route('lead-pipeline.reports.show', $report->share_token), 40) !!}
            </span>
        </span>
    </div>
</footer>
