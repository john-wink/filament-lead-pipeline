@if ($creatives->isNotEmpty())
    <section>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('lead-pipeline::reports.sections.creatives') }}</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($creatives as $creative)
                <figure class="overflow-hidden rounded-xl bg-white shadow-sm">
                    <img src="{{ Illuminate\Support\Facades\Storage::disk(config('lead-pipeline.reports.media_disk'))->url($creative->image_path) }}"
                         alt="{{ $creative->name }}" loading="lazy" class="aspect-square w-full object-cover">
                    <figcaption class="px-3 py-2 text-xs text-gray-600">
                        <span class="block truncate">{{ $creative->name }}</span>
                        <span class="mt-0.5 block tabular-nums text-gray-400">
                            {{ __('lead-pipeline::reports.creatives_totals', [
                                'impressions' => number_format($creative->lifetime_impressions, 0, ',', '.'),
                                'leads'       => number_format($creative->lifetime_leads, 0, ',', '.'),
                                'spend'       => number_format((float) $creative->lifetime_spend, 2, ',', '.'),
                            ]) }}
                        </span>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </section>
@endif
