<div
    class="group relative bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 hover:shadow-md transition-all duration-200"
    style="border-left: 3px solid {{ $phase?->color ?? '#6B7280' }}"
>
    {{-- Zone 1: Drag-Handle — ganzer Header-Bereich --}}
    <div class="flex items-start justify-between gap-2 p-3 pb-0 cursor-grab active:cursor-grabbing" data-drag-handle>
        <div class="flex items-center gap-2 min-w-0">
            <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-900 flex items-center justify-center flex-shrink-0">
                <span class="text-xs font-medium text-primary-700 dark:text-primary-300">
                    {{ strtoupper(substr($lead->name, 0, 2)) }}
                </span>
            </div>
            <span class="font-medium text-sm text-gray-900 dark:text-white truncate">{{ $lead->name }}</span>
        </div>
        <div class="flex items-center gap-1.5">
            @if($lead->value)
                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 whitespace-nowrap">
                    {{ number_format($lead->value, 0, ',', '.') }} €
                </span>
            @endif
            <x-heroicon-m-bars-3 class="w-4 h-4 text-gray-300 dark:text-gray-600 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0" />
        </div>
    </div>

    {{-- Zone 2: Clickable Content — opens SlideOver --}}
    <div class="px-3 pb-3 pt-2 cursor-pointer" x-on:click="$dispatch('open-lead-detail', { leadId: '{{ $lead->getKey() }}' })">
        {{-- Contact Info --}}
        <div class="space-y-1 mb-2">
            @if($lead->email)
                <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                    <x-heroicon-m-envelope class="w-3 h-3 flex-shrink-0" />
                    <a href="mailto:{{ $lead->email }}" x-on:click.stop class="truncate hover:text-primary-600 dark:hover:text-primary-400 hover:underline transition-colors">{{ $lead->email }}</a>
                </div>
            @endif
            @if($lead->phone)
                <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                    <x-heroicon-m-phone class="w-3 h-3 flex-shrink-0" />
                    <a href="tel:{{ $lead->phone }}" x-on:click.stop class="hover:text-primary-600 dark:hover:text-primary-400 hover:underline transition-colors">{{ $lead->phone }}</a>
                </div>
            @endif
        </div>

        {{-- Custom Fields --}}
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

        {{-- Footer: Source + Assigned User --}}
        <div class="flex items-center justify-between text-xs text-gray-400 dark:text-gray-500 pt-1 border-t border-gray-100 dark:border-gray-700">
            @if($lead->source)
                <span class="flex items-center gap-1">
                    <x-heroicon-m-arrow-down-tray class="w-3 h-3" />
                    {{ $lead->source->name }}
                </span>
            @else
                <span></span>
            @endif
            @if($lead->assignedUser)
                <span class="flex items-center gap-1">
                    <x-heroicon-m-user class="w-3 h-3" />
                    {{ $lead->assignedUser->name }}
                </span>
            @endif
        </div>
    </div>

    {{-- Zone 3: Quick Assign (slides in on hover, only when unassigned) --}}
    @if(!$lead->assigned_to && $lead->board && auth()->user() && $lead->board->isAdmin(auth()->user()))
        <div class="max-h-0 overflow-hidden opacity-0 group-hover:max-h-12 group-hover:opacity-100 transition-all duration-200 ease-in-out px-3 group-hover:pb-2" x-on:click.stop>
            <select
                wire:change="assignUser($event.target.value)"
                class="w-full text-xs rounded-lg border-gray-200 bg-gray-50 py-1.5 pl-2 pr-7 text-gray-600 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 cursor-pointer"
            >
                <option value="">{{ __('lead-pipeline::lead-pipeline.actions.assign') }}...</option>
                @foreach(\JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin::getAssignableUsers() as $assignableUser)
                    <option value="{{ $assignableUser->getKey() }}">{{ $assignableUser->name }}</option>
                @endforeach
            </select>
        </div>
    @endif
</div>
