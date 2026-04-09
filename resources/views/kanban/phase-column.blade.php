<div class="lead-phase-column" wire:key="phase-col-{{ $phase->getKey() }}" wire:init="init">
    {{-- Header with phase color gradient background --}}
    <div class="lead-phase-header"
        style="border-left: 4px solid {{ $phase->color ?? '#6B7280' }}; background: linear-gradient(135deg, {{ $phase->color ?? '#6B7280' }}08, {{ $phase->color ?? '#6B7280' }}03);">
        <div class="flex items-center gap-2.5">
            <span class="font-semibold text-sm text-gray-900 dark:text-gray-100">{{ $phase->name }}</span>
            <span class="lead-count-badge text-white"
                style="background-color: {{ $phase->color ?? '#6B7280' }}"
                x-data="{ count: {{ $totalCount }}, prevCount: {{ $totalCount }} }"
                x-effect="if (count !== prevCount) { $el.classList.add('pulse'); setTimeout(() => $el.classList.remove('pulse'), 400); prevCount = count; }"
                x-text="count">
                {{ $totalCount }}
            </span>
        </div>
        <div class="flex items-center gap-2">
            @php
                $phaseSum = $leads->sum(fn ($l) => (float) $l->value);
            @endphp
            @if($phaseSum > 0)
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    {{ number_format($phaseSum, 0, ',', '.') }}&euro;
                </span>
            @endif
            {{-- Sort dropdown --}}
            <div x-data="{ sortOpen: false }" class="relative">
                <button type="button" @click="sortOpen = !sortOpen"
                    class="flex items-center justify-center h-6 w-6 rounded-md transition-colors"
                    :class="$wire.sortBy !== 'sort' ? 'text-primary-600 bg-primary-50 dark:text-primary-400 dark:bg-primary-900/30' : 'text-gray-400 hover:text-gray-600 hover:bg-gray-200/60 dark:hover:text-gray-300 dark:hover:bg-gray-700/60'"
                    title="{{ __('lead-pipeline::lead-pipeline.sort.label') }}">
                    <x-heroicon-o-arrows-up-down class="h-4 w-4" />
                </button>
                <div x-show="sortOpen" @click.outside="sortOpen = false" x-cloak x-transition
                    class="absolute right-0 top-full mt-1 z-20 w-48 rounded-lg bg-white border border-gray-200 shadow-lg dark:bg-gray-800 dark:border-gray-700 py-1">
                    @foreach([
                        'sort'       => __('lead-pipeline::lead-pipeline.sort.manual'),
                        'newest'     => __('lead-pipeline::lead-pipeline.sort.newest'),
                        'oldest'     => __('lead-pipeline::lead-pipeline.sort.oldest'),
                        'value_desc' => __('lead-pipeline::lead-pipeline.sort.value_desc'),
                        'name_asc'   => __('lead-pipeline::lead-pipeline.sort.name_asc'),
                    ] as $sortValue => $sortLabel)
                        <button type="button"
                            wire:click="$set('sortBy', '{{ $sortValue }}')"
                            @click="sortOpen = false"
                            @class([
                                'w-full text-left px-3 py-1.5 text-xs transition-colors',
                                'text-primary-600 bg-primary-50 font-medium dark:text-primary-400 dark:bg-primary-900/30' => $sortBy === $sortValue,
                                'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700' => $sortBy !== $sortValue,
                            ])>
                            {{ $sortLabel }}
                        </button>
                    @endforeach
                </div>
            </div>
            <button
                type="button"
                x-data
                @click="$dispatch('create-lead', { phaseId: '{{ $phase->getKey() }}' })"
                class="flex items-center justify-center h-6 w-6 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-200/60 dark:hover:text-gray-300 dark:hover:bg-gray-700/60 transition-colors"
                title="Lead hinzufuegen">
                <x-heroicon-o-plus class="h-4 w-4" />
            </button>
        </div>
    </div>

    {{-- Search with icon --}}
    <div class="px-2 py-1.5">
        <div class="relative">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5">
                <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5 text-gray-400 dark:text-gray-500" />
            </div>
            <input type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('lead-pipeline::lead-pipeline.actions.search') }}"
                class="w-full text-xs rounded-lg border-gray-200 bg-white pl-8 pr-3 py-1.5 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-200 focus:border-primary-500 focus:ring-primary-500 placeholder:text-gray-400 dark:placeholder:text-gray-500 transition-colors">
        </div>
    </div>

    {{-- Loading skeleton --}}
    @if($loading ?? false)
        <div class="px-2 space-y-2 py-2">
            <div class="lead-skeleton"></div>
            <div class="lead-skeleton"></div>
            <div class="lead-skeleton"></div>
        </div>
    @else
    <div wire:loading.delay wire:target="search" class="px-2 space-y-2">
        <div class="lead-skeleton"></div>
        <div class="lead-skeleton"></div>
    </div>

    {{-- Sortable body --}}
    <div class="lead-phase-body" data-sortable-phase="{{ $phase->getKey() }}" wire:loading.class="opacity-60" wire:target="search">
        @forelse($leads as $lead)
            <div data-lead-id="{{ $lead->getKey() }}">
                @include('lead-pipeline::kanban.lead-card-inline', ['lead' => $lead, 'phase' => $phase, 'isAdmin' => $isAdmin ?? false, 'assignableUsers' => $assignableUsers ?? collect()])
            </div>
        @empty
            {{-- Empty state --}}
            <div class="lead-phase-empty">
                <x-heroicon-o-arrow-down-tray class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                <span class="text-xs text-gray-400 dark:text-gray-500 text-center">{{ __('lead-pipeline::lead-pipeline.lead.drag_here') }}</span>
            </div>
        @endforelse

        {{-- Infinite Scroll Sentinel (INSIDE scrollable container) --}}
        @if($hasMore)
            <div
                x-intersect.margin.100px="$wire.loadMore()"
                class="p-2 text-center"
            >
                <span class="text-xs text-gray-400 dark:text-gray-500" wire:loading.remove wire:target="loadMore">
                    {{ __('lead-pipeline::lead-pipeline.lead.remaining', ['count' => $totalCount - count($leads)]) }}
                </span>
                <span wire:loading wire:target="loadMore" class="inline-flex items-center gap-1 text-xs text-gray-400">
                    <svg class="h-3.5 w-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    {{ __('lead-pipeline::lead-pipeline.lead.loading') }}
                </span>
            </div>
        @endif
    </div>
    @endif
</div>
