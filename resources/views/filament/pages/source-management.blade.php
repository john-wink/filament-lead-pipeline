<x-filament-panels::page>
    @if($this->editingFunnelSourceId)
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <button
                    wire:click="$set('editingFunnelSourceId', null)"
                    class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors"
                >
                    <x-heroicon-m-arrow-left class="w-4 h-4" />
                    {{ __('lead-pipeline::lead-pipeline.source.back_to_sources') }}
                </button>
            </div>

            @livewire('lead-pipeline::funnel-builder', ['sourceId' => $this->editingFunnelSourceId], key('funnel-' . $this->editingFunnelSourceId))
        </div>
    @else
        {{ $this->table }}
    @endif
</x-filament-panels::page>
