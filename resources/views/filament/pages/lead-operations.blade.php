<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-2">
        <select wire:change="setBoard($event.target.value)" class="fi-select-input rounded-lg border-gray-300 dark:bg-gray-800">
            <option value="all">{{ __('lead-pipeline::lead-pipeline.analytics.all_boards') }}</option>
            @foreach ($boards as $id => $name)
                <option value="{{ $id }}" @selected($boardId === (string) $id)>{{ $name }}</option>
            @endforeach
        </select>

        @foreach (['today' => __('lead-pipeline::lead-pipeline.analytics.today'), '7' => __('lead-pipeline::lead-pipeline.analytics.days_7'), '30' => __('lead-pipeline::lead-pipeline.analytics.days_30'), '90' => __('lead-pipeline::lead-pipeline.analytics.days_90')] as $key => $label)
            <x-filament::button size="sm" :color="$preset === $key ? 'primary' : 'gray'" wire:click="setPreset('{{ $key }}')">{{ $label }}</x-filament::button>
        @endforeach
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
            <div class="text-sm text-gray-500">{{ __('lead-pipeline::lead-pipeline.operations.overdue_followups') }}</div>
            <div class="text-2xl font-bold tabular-nums">{{ $operations['overdue_followups'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('lead-pipeline::lead-pipeline.operations.untouched') }}</div>
            <div class="text-2xl font-bold tabular-nums">{{ $operations['untouched'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('lead-pipeline::lead-pipeline.operations.avg_contact_attempts') }}</div>
            <div class="text-2xl font-bold tabular-nums">{{ number_format($operations['avg_contact_attempts'], 1, ',', '.') }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('lead-pipeline::lead-pipeline.operations.next_step_rate') }}</div>
            <div class="text-2xl font-bold tabular-nums">{{ number_format($operations['next_step_rate'], 1, ',', '.') }} %</div>
        </x-filament::section>
    </div>

    @include('lead-pipeline::filament.pages.lead-operations-detail')
</x-filament-panels::page>
