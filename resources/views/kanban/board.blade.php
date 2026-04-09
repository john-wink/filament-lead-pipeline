<div
    data-kanban-board
    wire:id="{{ $this->getId() }}"
    class="flex flex-col gap-2 h-full overflow-hidden"
    @if(config('lead-pipeline.kanban.auto_refresh_interval', 0) > 0)
        wire:poll.{{ config('lead-pipeline.kanban.auto_refresh_interval') }}s
    @endif
>
    {{-- Kanban Board --}}
    <div class="lead-kanban-board">
        @foreach($phases as $phase)
            @livewire('lead-pipeline::kanban-phase-column', ['phaseId' => $phase->getKey()], key('phase-'.$phase->getKey()))
        @endforeach
    </div>

    @if($showCreateModal)
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
