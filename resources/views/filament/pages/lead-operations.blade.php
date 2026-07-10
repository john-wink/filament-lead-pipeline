<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-2">
        <select wire:change="setBoard($event.target.value)" class="fi-select-input rounded-lg border-gray-300 dark:bg-gray-800">
            <option value="all">{{ __('lead-pipeline::lead-pipeline.analytics.all_boards') }}</option>
            @foreach ($boards as $id => $name)
                <option value="{{ $id }}" @selected($boardId === (string) $id)>{{ $name }}</option>
            @endforeach
        </select>

        <select wire:change="setAdvisor($event.target.value)" class="fi-select-input rounded-lg border-gray-300 dark:bg-gray-800">
            <option value="all">{{ __('lead-pipeline::lead-pipeline.operations.all_advisors') }}</option>
            @foreach ($advisorOptions as $id => $name)
                <option value="{{ $id }}" @selected($advisorId === (string) $id)>{{ $name }}</option>
            @endforeach
        </select>

        @foreach (['today' => __('lead-pipeline::lead-pipeline.analytics.today'), '7' => __('lead-pipeline::lead-pipeline.analytics.days_7'), '30' => __('lead-pipeline::lead-pipeline.analytics.days_30'), '90' => __('lead-pipeline::lead-pipeline.analytics.days_90'), 'all' => __('lead-pipeline::lead-pipeline.analytics.all')] as $key => $label)
            <x-filament::button size="sm" :color="$preset === $key ? 'primary' : 'gray'" wire:click="setPreset('{{ $key }}')">{{ $label }}</x-filament::button>
        @endforeach

        <div class="flex items-center gap-1 text-sm">
            <input type="date" wire:model.live="dateFrom" class="fi-input rounded-lg border-gray-300 text-sm dark:bg-gray-800" />
            <span class="text-gray-400">–</span>
            <input type="date" wire:model.live="dateTo" class="fi-input rounded-lg border-gray-300 text-sm dark:bg-gray-800" />
        </div>

        <a href="{{ $this->getExportUrl() }}" class="ms-auto">
            <x-filament::button size="sm" icon="heroicon-o-arrow-down-tray" tag="span">{{ __('lead-pipeline::lead-pipeline.operations.export') }}</x-filament::button>
        </a>
    </div>

    <div class="mt-6 grid grid-cols-2 gap-4 md:grid-cols-4 xl:grid-cols-6">
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('lead-pipeline::lead-pipeline.operations.avg_first_response') }}</div>
            <div class="text-2xl font-bold tabular-nums">{{ $response['avg_minutes'] !== null ? number_format($response['avg_minutes'], 1, ',', '.') . ' min' : '–' }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('lead-pipeline::lead-pipeline.operations.sla') }}</div>
            <div class="text-2xl font-bold tabular-nums">{{ number_format($response['sla_pct'], 1, ',', '.') }} %</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('lead-pipeline::lead-pipeline.operations.overdue_followups') }}<span class="ms-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-500 dark:bg-gray-800">{{ __('lead-pipeline::lead-pipeline.operations.as_of_today') }}</span></div>
            <div class="text-2xl font-bold tabular-nums">{{ $operations['overdue_followups'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('lead-pipeline::lead-pipeline.operations.untouched') }}<span class="ms-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-500 dark:bg-gray-800">{{ __('lead-pipeline::lead-pipeline.operations.as_of_today') }}</span></div>
            <div class="text-2xl font-bold tabular-nums">{{ $operations['untouched'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('lead-pipeline::lead-pipeline.operations.avg_contact_attempts') }}<span class="ms-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-500 dark:bg-gray-800">{{ __('lead-pipeline::lead-pipeline.operations.as_of_today') }}</span></div>
            <div class="text-2xl font-bold tabular-nums">{{ number_format($operations['avg_contact_attempts'], 1, ',', '.') }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('lead-pipeline::lead-pipeline.operations.next_step_rate') }}<span class="ms-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-500 dark:bg-gray-800">{{ __('lead-pipeline::lead-pipeline.operations.as_of_today') }}</span></div>
            <div class="text-2xl font-bold tabular-nums">{{ number_format($operations['next_step_rate'], 1, ',', '.') }} %</div>
        </x-filament::section>
    </div>

    @include('lead-pipeline::filament.pages.lead-operations-detail')
</x-filament-panels::page>
