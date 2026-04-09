<x-filament-panels::page>
    @if($this->activeTab === 'board')
    <style>
        /* Kanban: kill scrolling on ancestors so the board fills the viewport */
        html, body { overflow: hidden !important; height: 100vh !important; }
        .fi-layout, .fi-main, .fi-main-ctn, .fi-topbar + div,
        .fi-page, .fi-page-content, .fi-section, .fi-section-content-ctn,
        [class*="fi-body"], [class*="fi-main"] {
            overflow: hidden !important;
            max-height: 100vh !important;
        }
    </style>
    @endif
    <style>
        [data-kanban-page] {
            display: flex; flex-direction: column; overflow: hidden; min-height: 0;
        }
    </style>
    <script>
        // After full render: measure exact offset and set height
        requestAnimationFrame(() => {
            const el = document.querySelector('[data-kanban-page]');
            if (!el) return;
            const top = el.getBoundingClientRect().top;
            el.style.height = (window.innerHeight - top - 8) + 'px';

            // Also kill overflow on every ancestor up to body
            let node = el.parentElement;
            while (node && node !== document.documentElement) {
                node.style.overflow = 'hidden';
                node = node.parentElement;
            }
        });
    </script>

    <div data-kanban-page>
        {{-- Tab Navigation + Toolbar in one row --}}
        <div class="border-b border-gray-200 dark:border-gray-700 mb-2 flex-shrink-0">
            <div class="flex items-center justify-between gap-4">
                {{-- Left: Tabs --}}
                <nav class="flex gap-4 min-w-0" aria-label="Tabs">
                    <button
                        wire:click="setActiveTab('board')"
                        @class([
                            'px-3 py-2 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
                            'border-primary-500 text-primary-600 dark:text-primary-400' => $this->activeTab === 'board',
                            'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400' => $this->activeTab !== 'board',
                        ])
                    >
                        {{ __('lead-pipeline::lead-pipeline.board.tab_board') }}
                    </button>
                    @foreach($this->getListPhases() as $listPhase)
                        <button
                            wire:click="setActiveTab('{{ $listPhase->getKey() }}')"
                            @class([
                                'px-3 py-2 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
                                'border-primary-500 text-primary-600 dark:text-primary-400' => $this->activeTab === $listPhase->getKey(),
                                'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400' => $this->activeTab !== $listPhase->getKey(),
                            ])
                        >
                            <span class="inline-block w-2 h-2 rounded-full mr-1.5" style="background: {{ $listPhase->color }}"></span>
                            {{ $listPhase->name }}
                            <span class="ml-1 text-xs text-gray-400">({{ $listPhase->leads_count }})</span>
                        </button>
                    @endforeach
                </nav>

                {{-- Right: Stats + Filter + New Lead --}}
                <div class="flex items-center gap-2 flex-shrink-0">
                    @if($this->activeTab === 'board')
                        @php
                            $kanbanPhases = $this->board->phases()->kanban()->ordered()->get();
                            $totalLeads = $kanbanPhases->sum(fn ($p) => $p->leads()->count());
                            $totalValue = $kanbanPhases->sum(fn ($p) => (float) $p->leads()->sum('value'));
                        @endphp
                        <div class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1 dark:bg-gray-800">
                            <x-heroicon-o-users class="h-3.5 w-3.5 text-gray-500 dark:text-gray-400" />
                            <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ number_format($totalLeads) }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.lead.plural') }}</span>
                        </div>
                        <div class="flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 dark:bg-emerald-900/20">
                            <x-heroicon-o-currency-euro class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                            <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                                {{ number_format($totalValue, 0, ',', '.') }} &euro;
                            </span>
                        </div>
                        <span class="mx-1 h-5 w-px bg-gray-200 dark:bg-gray-700"></span>
                    @endif
                    <button
                        type="button"
                        wire:click="toggleFilters"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium border transition-colors',
                            'bg-primary-50 text-primary-700 border-primary-300 dark:bg-primary-900/30 dark:text-primary-400 dark:border-primary-600' => $this->showFilters,
                            'bg-white text-gray-700 border-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700' => ! $this->showFilters,
                        ])
                    >
                        <x-heroicon-o-funnel class="h-3.5 w-3.5" />
                        {{ __('lead-pipeline::lead-pipeline.actions.filter') }}
                        @if(count(array_filter($this->filters, fn ($v) => filled($v))) > 0)
                            <span class="inline-flex items-center justify-center w-4 h-4 text-[10px] font-bold rounded-full bg-primary-600 text-white">
                                {{ count(array_filter($this->filters, fn ($v) => filled($v))) }}
                            </span>
                        @endif
                    </button>
                    <button
                        type="button"
                        x-on:click="$dispatch('open-analytics', { boardId: '{{ $this->board->getKey() }}' })"
                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700 transition-colors"
                    >
                        <x-heroicon-o-chart-bar class="h-3.5 w-3.5" />
                        {{ __('lead-pipeline::lead-pipeline.analytics.title') }}
                    </button>
                    @if($this->activeTab === 'board')
                        <button
                            type="button"
                            x-on:click="$dispatch('open-create-modal')"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1 text-xs font-medium text-white shadow-sm hover:bg-primary-700 transition-colors"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                            {{ __('lead-pipeline::lead-pipeline.lead.new_lead') }}
                        </button>
                    @endif
                </div>
            </div>
        </div>

        {{-- Filter Bar (page-level, shared between board and list) --}}
        @if($this->showFilters)
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800 mb-2 flex-shrink-0">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.filter.source') }}</label>
                        <select wire:model.live="filters.source_id"
                            class="w-full rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-700 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            <option value="">{{ __('lead-pipeline::lead-pipeline.filter.all') }}</option>
                            @foreach($this->board->sources()->get() as $source)
                                <option value="{{ $source->getKey() }}">{{ $source->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.filter.assigned_to') }}</label>
                        <select wire:model.live="filters.assigned_to"
                            class="w-full rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-700 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            <option value="">{{ __('lead-pipeline::lead-pipeline.filter.all') }}</option>
                            @foreach(\JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin::getAssignableUsers() as $user)
                                <option value="{{ $user->getKey() }}">{{ $user->display_label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.filter.status') }}</label>
                        <select wire:model.live="filters.status"
                            class="w-full rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-700 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            <option value="">{{ __('lead-pipeline::lead-pipeline.filter.all') }}</option>
                            @foreach(\JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum::cases() as $status)
                                <option value="{{ $status->value }}">{{ $status->getLabel() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.filter.value_from') }}</label>
                        <input type="number" wire:model.live.debounce.500ms="filters.value_min" placeholder="Min"
                            class="w-full rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-700 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.filter.value_to') }}</label>
                        <input type="number" wire:model.live.debounce.500ms="filters.value_max" placeholder="Max"
                            class="w-full rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-700 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.filter.created_from') }}</label>
                        <input type="date" wire:model.live="filters.created_from"
                            class="w-full rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-700 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300" />
                    </div>
                </div>
            </div>
        @endif

        {{-- Content (fills remaining space) --}}
        <div class="flex-1 overflow-hidden min-h-0">
            @if($this->activeTab === 'board')
                @livewire('lead-pipeline::kanban-board', ['board' => $this->board])
            @else
                @livewire('lead-pipeline::phase-list-table', ['phaseId' => $this->activeTab], key('list-' . $this->activeTab))
            @endif
        </div>

        @livewire('lead-pipeline::lead-detail-modal')
        @livewire('lead-pipeline::lead-analytics-modal')
    </div>
</x-filament-panels::page>
