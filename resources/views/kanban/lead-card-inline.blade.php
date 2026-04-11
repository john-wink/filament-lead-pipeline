<div
    class="group relative bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 hover:shadow-md transition-all duration-200"
    style="border-left: 3px solid {{ $phase?->color ?? '#6B7280' }}"
>
    {{-- Zone 1: Drag-Handle --}}
    <div class="flex items-start justify-between gap-2 p-3 pb-0 cursor-grab active:cursor-grabbing" data-drag-handle>
        <span class="font-medium text-sm text-gray-900 dark:text-white truncate min-w-0">{{ $lead->name }}</span>
        <div class="flex items-center gap-1.5">
            @if($lead->value)
                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 whitespace-nowrap">
                    {{ number_format($lead->value, 0, ',', '.') }} €
                </span>
            @endif
            <x-heroicon-m-bars-3 class="w-4 h-4 text-gray-300 dark:text-gray-600 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0" />
        </div>
    </div>

    {{-- Zone 2: Klickbarer Content --}}
    <div class="px-3 pb-3 pt-2 cursor-pointer" x-on:click="$dispatch('open-lead-detail', { leadId: '{{ $lead->getKey() }}' })">
        <div class="space-y-1 mb-2">
            @if($lead->email)
                <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $lead->email }}</div>
            @endif
            @if($lead->phone)
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $lead->phone }}</div>
            @endif
        </div>

        @php
            $cardFields = $lead->fieldValues
                ->filter(fn ($fv) => $fv->definition?->show_in_card)
                ->take(config('lead-pipeline.kanban.card_fields_limit', 5));
        @endphp
        @if($cardFields->isNotEmpty())
            <div class="flex flex-wrap gap-1 mb-2">
                @foreach($cardFields as $fieldValue)
                    @php
                        $rawValue = $fieldValue->display_value;
                        $displayValue = match($fieldValue->definition?->type?->value) {
                            'currency' => number_format((float) $fieldValue->value, 0, ',', '.') . ' €',
                            'decimal' => number_format((float) $fieldValue->value, 2, ',', '.'),
                            'number' => number_format((int) $fieldValue->value, 0, ',', '.'),
                            'boolean' => $fieldValue->value ? __('lead-pipeline::lead-pipeline.field.yes') : __('lead-pipeline::lead-pipeline.field.no'),
                            'date' => $fieldValue->value ? \Carbon\Carbon::parse($fieldValue->value)->format('d.m.Y') : '',
                            default => Str::limit($rawValue, 25),
                        };
                    @endphp
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 max-w-full truncate"
                        title="{{ $fieldValue->definition->name }}: {{ $rawValue }}">
                        {{ $fieldValue->definition->name }}: {{ $displayValue }}
                    </span>
                @endforeach
            </div>
        @endif

        <div class="flex items-center justify-between text-xs text-gray-400 dark:text-gray-500 pt-1 border-t border-gray-100 dark:border-gray-700">
            <span class="truncate">{{ $lead->source?->name }}</span>
            <span class="truncate text-right">{{ $lead->assignedUser?->name }}</span>
        </div>
    </div>

    {{-- Zone 3: Quick Assign (uses pre-loaded data from column, no DB queries) --}}
    @if(!$lead->assigned_to && ($isAdmin ?? false) && $assignableUsers->isNotEmpty())
        <div class="max-h-0 overflow-hidden opacity-0 group-hover:max-h-12 group-hover:opacity-100 transition-all duration-200 ease-in-out px-3 group-hover:pb-2" x-on:click.stop>
            <select
                x-on:change="$wire.assignUser('{{ $lead->getKey() }}', $event.target.value)"
                class="w-full text-xs rounded-lg border-gray-200 bg-gray-50 py-1.5 pl-2 pr-7 text-gray-600 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 cursor-pointer"
            >
                <option value="">{{ __('lead-pipeline::lead-pipeline.actions.assign') }}...</option>
                @foreach($assignableUsers as $assignableUser)
                    <option value="{{ $assignableUser->getKey() }}">{{ $assignableUser->display_label }}</option>
                @endforeach
            </select>
        </div>
    @endif
</div>
