<x-filament-panels::page>
    <div
        data-kanban-page
        @class([
            'flex flex-col min-h-0',
            'overflow-hidden' => $this->activeTab === 'board',
        ])
        @if($this->activeTab === 'board')
            style="height: calc(100vh - 15rem); max-height: calc(100dvh - 15rem);"
        @endif
    >
        {{-- Tab Navigation + Toolbar in one row --}}
        <div x-data="{ showConnectionStatus: false }" class="border-b border-gray-200 dark:border-gray-700 mb-2 flex-shrink-0">
            {{-- Zeile 1: Navigation (Tabs) + Aktionen --}}
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                {{-- Tabs --}}
                <nav class="flex flex-wrap items-center gap-x-4 gap-y-1 min-w-0" aria-label="Tabs">
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

                {{-- Aktionen: sekundär (Ghost) + Trenner + primärer CTA --}}
                <div class="flex flex-wrap items-center gap-2">
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
                    @can('viewAny', \JohnWink\FilamentLeadPipeline\Models\LeadReport::class)
                        <a href="{{ \JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource::getUrl('edit', ['record' => $this->board]) }}"
                            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700 transition-colors">
                            <x-heroicon-o-document-chart-bar class="h-3.5 w-3.5" />
                            {{ __('lead-pipeline::reports.resource.plural') }}
                        </a>
                    @endcan
                    {{-- Facebook-Verbindungs-Ampel (icon-only) --}}
                    @php $alerts = $this->connectionAlerts; @endphp
                    <button type="button" @click="showConnectionStatus = true"
                        class="relative inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 transition-colors"
                        aria-label="Facebook" title="{{ __('lead-pipeline::lead-pipeline.connection_status.title') }}">
                        <x-heroicon-o-share class="h-3.5 w-3.5" />
                        <span @class([
                            'absolute -top-0.5 -right-0.5 inline-block h-2.5 w-2.5 rounded-full ring-2 ring-white dark:ring-gray-800',
                            'bg-red-500'     => 'critical' === $alerts['connection_state'] || $alerts['error_sources'] > 0,
                            'bg-amber-500'   => 'warning' === $alerts['connection_state'] && 0 === $alerts['error_sources'],
                            'bg-emerald-500' => 'ok' === $alerts['connection_state'] && 0 === $alerts['error_sources'],
                        ])></span>
                    </button>
                    @if($this->activeTab === 'board')
                        <span class="mx-0.5 h-5 w-px bg-gray-200 dark:bg-gray-700"></span>
                        <button
                            type="button"
                            x-on:click="$dispatch('open-create-modal')"
                            class="inline-flex items-center justify-center rounded-lg bg-primary-600 px-2.5 py-1.5 text-white shadow-sm hover:bg-primary-700 transition-colors"
                            aria-label="{{ __('lead-pipeline::lead-pipeline.lead.add') }}"
                            title="{{ __('lead-pipeline::lead-pipeline.lead.add') }}"
                        >
                            <x-heroicon-o-plus class="h-4 w-4" />
                        </button>
                    @endif
                </div>
            </div>

            {{-- Zeile 2: Suche + Kennzahlen (nur Board-Ansicht) --}}
            @if($this->activeTab === 'board')
                @php
                    $totalLeads = $this->boardStats['leads'];
                    $totalValue = $this->boardStats['value'];
                @endphp
                <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-gray-100 dark:border-gray-800 pt-2">
                    {{-- Zentrale Suche über alle Spalten --}}
                    <div class="relative" wire:loading.class="opacity-60" wire:target="search">
                        <x-heroicon-o-magnifying-glass class="pointer-events-none -translate-y-1/2 start-2 top-1/2 absolute h-3.5 w-3.5 text-gray-400 dark:text-gray-500" />
                        <input type="text"
                            wire:model.live.debounce.400ms="search"
                            placeholder="{{ __('lead-pipeline::lead-pipeline.actions.search') }}"
                            class="w-56 max-w-full text-xs rounded-lg border-gray-200 bg-white ps-7 pr-3 py-1.5 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-200 focus:border-primary-500 focus:ring-primary-500 placeholder:text-gray-400 dark:placeholder:text-gray-500 transition-colors">
                    </div>
                    {{-- Kennzahlen --}}
                    <div class="flex flex-wrap items-center gap-2">
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
                    </div>
                </div>
            @endif

            {{-- Facebook Status-Modal --}}
            <template x-teleport="body">
                <div x-show="showConnectionStatus" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showConnectionStatus = false">
                    <div class="mx-4 w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-gray-900">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('lead-pipeline::lead-pipeline.connection_status.title') }}</h3>
                            <button type="button" @click="showConnectionStatus = false" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800">
                                <x-heroicon-o-x-mark class="h-5 w-5" />
                            </button>
                        </div>
                        @livewire('lead-pipeline::facebook-connection-status')
                    </div>
                </div>
            </template>
        </div>

        {{-- „Mein Tag": persönliche KPIs des Beraters, ohne Modal-Kontextwechsel --}}
        @if(auth()->check())
            @php $myDay = $this->myDayStats; @endphp
            <div class="lead-my-day-strip mb-2 flex flex-wrap items-center gap-2 flex-shrink-0">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    {{ __('lead-pipeline::lead-pipeline.my_day.title') }}
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1 text-xs dark:bg-gray-800">
                    <x-heroicon-o-sparkles class="h-3.5 w-3.5 text-primary-500" />
                    <span class="font-semibold text-gray-700 dark:text-gray-300">{{ $myDay['new_today'] }}</span>
                    <span class="text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.my_day.new_today') }}</span>
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1 text-xs dark:bg-gray-800">
                    <x-heroicon-o-phone class="h-3.5 w-3.5 text-primary-500" />
                    <span class="font-semibold text-gray-700 dark:text-gray-300">{{ $myDay['contacted_today'] }}</span>
                    <span class="text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.my_day.contacted_today') }}</span>
                </span>
                <span @class([
                    'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs',
                    'bg-red-50 dark:bg-red-900/20'   => $myDay['due_today'] > 0,
                    'bg-gray-100 dark:bg-gray-800'   => 0 === $myDay['due_today'],
                ])>
                    <x-heroicon-o-bell-alert @class([
                        'h-3.5 w-3.5',
                        'text-red-600 dark:text-red-400'     => $myDay['due_today'] > 0,
                        'text-gray-500 dark:text-gray-400'   => 0 === $myDay['due_today'],
                    ]) />
                    <span @class([
                        'font-semibold',
                        'text-red-700 dark:text-red-300'     => $myDay['due_today'] > 0,
                        'text-gray-700 dark:text-gray-300'   => 0 === $myDay['due_today'],
                    ])>{{ $myDay['due_today'] }}</span>
                    <span @class([
                        'text-red-700/70 dark:text-red-300/70' => $myDay['due_today'] > 0,
                        'text-gray-500 dark:text-gray-400'     => 0 === $myDay['due_today'],
                    ])>{{ __('lead-pipeline::lead-pipeline.my_day.due_today') }}</span>
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs dark:bg-emerald-900/20">
                    <x-heroicon-o-trophy class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                    <span class="font-semibold text-emerald-700 dark:text-emerald-300">{{ $myDay['won_week'] }}</span>
                    <span class="text-emerald-700/70 dark:text-emerald-300/70">{{ __('lead-pipeline::lead-pipeline.my_day.won_week') }}</span>
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1 text-xs dark:bg-gray-800">
                    <x-heroicon-o-inbox-stack class="h-3.5 w-3.5 text-gray-500 dark:text-gray-400" />
                    <span class="font-semibold text-gray-700 dark:text-gray-300">{{ $myDay['open_mine'] }}</span>
                    <span class="text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.my_day.open_mine') }}</span>
                </span>
            </div>
        @endif

        {{-- Health-Banner: stille Lead-Ausfälle sichtbar machen (Quellen auf Error / Verbindung kritisch) --}}
        @php $connectionAlerts = $this->connectionAlerts; @endphp
        @if($connectionAlerts['error_sources'] > 0 || 'critical' === $connectionAlerts['connection_state'])
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-red-300 bg-red-50 px-4 py-2.5 dark:border-red-800 dark:bg-red-900/20 flex-shrink-0">
                <div class="flex items-center gap-2 text-sm font-medium text-red-800 dark:text-red-200">
                    <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0" />
                    @if($connectionAlerts['error_sources'] > 0)
                        {{ __('lead-pipeline::lead-pipeline.connection_status.banner_sources_error', ['count' => $connectionAlerts['error_sources']]) }}
                    @else
                        {{ __('lead-pipeline::lead-pipeline.connection_status.banner_connection_critical') }}
                    @endif
                </div>
                <a href="{{ \JohnWink\FilamentLeadPipeline\Filament\Pages\SourceManagement::getUrl() }}"
                    class="rounded-lg bg-red-600 px-3 py-1 text-xs font-semibold text-white hover:bg-red-700 transition-colors">
                    {{ __('lead-pipeline::lead-pipeline.connection_status.banner_action') }}
                </a>
            </div>
        @endif

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
        @if($this->activeTab === 'board')
            {{--
                Page rendert die PhaseColumns DIREKT — analog zum Loan-Kanban.
                Der wire:key enthält einen Hash der aktuellen Filter, sodass
                Livewire die isolierten PhaseColumns bei jeder Filter-Änderung
                deterministisch neu mountet. Damit umgehen wir das fragile
                Browser-Event-Routing zu #[Isolate]-Children komplett.
            --}}
            @php
                $filterKey = md5(json_encode($this->filters));
            @endphp
            <div
                data-kanban-board
                data-kanban-component-id="{{ $this->getId() }}"
                class="flex-1 overflow-hidden min-h-0 lead-kanban-board"
            >
                @foreach($this->getKanbanPhases() as $phase)
                    @livewire('lead-pipeline::kanban-phase-column', ['phaseId' => $phase->getKey(), 'filters' => $this->filters], key('phase-' . $phase->getKey() . '-' . $filterKey))
                @endforeach
            </div>
        @else
            <div class="flex-1 min-h-0">
                @livewire('lead-pipeline::phase-list-table', ['phaseId' => $this->activeTab], key('list-' . $this->activeTab))
            </div>
        @endif

        @livewire('lead-pipeline::lead-detail-modal')
        @livewire('lead-pipeline::lead-analytics-modal')

        @if($this->showCreateModal)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="$set('showCreateModal', false)">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl p-6 w-full max-w-md mx-4">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">{{ __('lead-pipeline::lead-pipeline.lead.create') }}</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('lead-pipeline::lead-pipeline.field.name') }} *</label>
                            <input wire:model="newLeadName" type="text" placeholder="{{ __('lead-pipeline::lead-pipeline.field.name_placeholder') }}"
                                class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-primary-500 focus:border-primary-500" />
                            @error('newLeadName') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('lead-pipeline::lead-pipeline.field.email') }}</label>
                            <input wire:model="newLeadEmail" type="email" placeholder="{{ __('lead-pipeline::lead-pipeline.field.email_placeholder') }}"
                                class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-primary-500 focus:border-primary-500" />
                            @error('newLeadEmail') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('lead-pipeline::lead-pipeline.field.phone') }}</label>
                            <input wire:model="newLeadPhone" type="tel" placeholder="{{ __('lead-pipeline::lead-pipeline.field.phone_placeholder') }}"
                                class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-primary-500 focus:border-primary-500" />
                            @error('newLeadPhone') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        @if($this->duplicateLeadName)
                            <div class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 dark:border-amber-700 dark:bg-amber-900/20">
                                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                <p class="text-xs text-amber-800 dark:text-amber-200">{{ __('lead-pipeline::lead-pipeline.lead.duplicate_warning', ['name' => $this->duplicateLeadName]) }}</p>
                            </div>
                        @endif
                        @if($this->isBoardAdmin)
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('lead-pipeline::lead-pipeline.field.assigned_advisor') }}</label>
                                <select wire:model="newLeadAssignedUserId"
                                    class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                                    <option value="">{{ __('lead-pipeline::lead-pipeline.field.assigned_advisor_none') }}</option>
                                    @foreach($this->advisorOptions as $userId => $label)
                                        <option value="{{ $userId }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                    <div class="flex gap-2 mt-6 justify-end">
                        <button wire:click="$set('showCreateModal', false)"
                            class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 transition-colors">
                            {{ __('lead-pipeline::lead-pipeline.actions.cancel') }}
                        </button>
                        <button wire:click="createLead({{ $this->duplicateLeadName ? 'true' : 'false' }})"
                            @class([
                                'px-4 py-2 text-sm text-white rounded-lg transition-colors',
                                'bg-amber-600 hover:bg-amber-700'     => $this->duplicateLeadName,
                                'bg-primary-600 hover:bg-primary-700' => ! $this->duplicateLeadName,
                            ])
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50">
                            <span wire:loading.remove wire:target="createLead">{{ $this->duplicateLeadName ? __('lead-pipeline::lead-pipeline.lead.create_anyway') : __('lead-pipeline::lead-pipeline.lead.create_btn') }}</span>
                            <span wire:loading wire:target="createLead">{{ __('lead-pipeline::lead-pipeline.lead.creating') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
