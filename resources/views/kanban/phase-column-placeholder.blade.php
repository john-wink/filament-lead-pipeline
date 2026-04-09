<div class="lead-phase-column" wire:key="phase-col-placeholder-{{ $phase?->getKey() }}">
    <div class="lead-phase-header"
        style="border-left: 4px solid {{ $phase->color ?? '#6B7280' }}; background: linear-gradient(135deg, {{ $phase->color ?? '#6B7280' }}08, {{ $phase->color ?? '#6B7280' }}03);">
        <div class="flex items-center gap-2.5">
            <span class="font-semibold text-sm text-gray-900 dark:text-gray-100">{{ $phase->name ?? '' }}</span>
            <span class="lead-count-badge text-white" style="background-color: {{ $phase->color ?? '#6B7280' }}">...</span>
        </div>
    </div>
    <div class="lead-cards-container p-2">
        @for($i = 0; $i < 3; $i++)
            <div class="animate-pulse mb-2">
                <div class="bg-gray-200 dark:bg-gray-700 rounded-lg h-20 w-full"></div>
            </div>
        @endfor
    </div>
</div>
