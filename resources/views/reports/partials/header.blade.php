<header class="sticky top-0 z-10 border-b bg-white/90 px-4 py-3 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            @if ($branding->logoUrl)<img src="{{ $branding->logoUrl }}" alt="" class="h-8">@endif
            @if ($branding->coLogoUrl)<img src="{{ $branding->coLogoUrl }}" alt="" class="h-6 opacity-80">@endif
            <h1 class="text-base font-semibold">{{ $report->name }}</h1>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-sm text-gray-500">{{ $range->from->format('d.m.Y') }} – {{ $range->till->format('d.m.Y') }}</div>
            @unless ($report->date_locked)
                <div class="flex items-center gap-2 text-sm" x-data="{ open: false }">
                    <div class="relative">
                        <button type="button" @click="open = ! open" class="rounded-lg border border-gray-300 px-3 py-1.5">
                            {{ $range->preset->label() }}
                        </button>
                        <div x-show="open" @click.outside="open = false" x-cloak
                             class="absolute right-0 z-20 mt-1 w-44 rounded-lg border bg-white py-1 shadow-lg">
                            @foreach (JohnWink\FilamentLeadPipeline\Enums\ReportDatePresetEnum::cases() as $presetCase)
                                @continue(JohnWink\FilamentLeadPipeline\Enums\ReportDatePresetEnum::Custom === $presetCase)
                                <button type="button" wire:click="setPreset('{{ $presetCase->value }}')" @click="open = false"
                                        class="block w-full px-3 py-1.5 text-left hover:bg-gray-50">
                                    {{ $presetCase->label() }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                    @if ('custom' === $preset)
                        <input type="date" wire:model.live="customFrom" class="rounded-lg border-gray-300 text-sm">
                        <input type="date" wire:model.live="customTill" class="rounded-lg border-gray-300 text-sm">
                    @endif
                </div>
            @endunless
        </div>
    </div>
</header>
