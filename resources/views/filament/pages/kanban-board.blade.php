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
        @if($this->activeTab === 'board')
            {{--
                Page rendert die PhaseColumns DIREKT — analog zum Loan-Kanban.
                Eine zusätzliche Livewire-Layer würde Browser-Events zu den
                isolierten PhaseColumns nach Page-Roundtrips abschneiden, was
                Live-Filter-Updates kaputt macht.
            --}}
            <div
                data-kanban-board
                data-kanban-component-id="{{ $this->getId() }}"
                class="flex-1 overflow-hidden min-h-0 lead-kanban-board"
            >
                @foreach($this->getKanbanPhases() as $phase)
                    @livewire('lead-pipeline::kanban-phase-column', ['phaseId' => $phase->getKey(), 'filters' => $this->filters], key('phase-' . $phase->getKey()))
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
                        <button wire:click="createLead"
                            class="px-4 py-2 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition-colors"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50">
                            <span wire:loading.remove wire:target="createLead">{{ __('lead-pipeline::lead-pipeline.lead.create_btn') }}</span>
                            <span wire:loading wire:target="createLead">{{ __('lead-pipeline::lead-pipeline.lead.creating') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
