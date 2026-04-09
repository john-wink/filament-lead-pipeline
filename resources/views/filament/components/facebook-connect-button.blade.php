<div x-data="{ checking: false }">
    @php
        $connection = \JohnWink\FilamentLeadPipeline\Models\FacebookConnection::query()
            ->where('user_uuid', auth()->id())
            ->where('status', 'connected')
            ->first();
    @endphp

    @if($connection)
        <div class="flex items-center gap-2 text-sm text-success-600 dark:text-success-400">
            <x-heroicon-o-check-circle class="w-5 h-5" />
            <span>{{ __('lead-pipeline::lead-pipeline.facebook.connected_as', ['name' => $connection->facebook_user_name]) }}</span>
        </div>
    @else
        <button
            type="button"
            x-on:click="
                checking = true;
                const popup = window.open(
                    '{{ route('lead-pipeline.facebook.redirect') }}',
                    'facebook_connect',
                    'width=600,height=700,scrollbars=yes,status=yes'
                );
                const interval = setInterval(() => {
                    if (!popup || popup.closed) {
                        clearInterval(interval);
                        checking = false;
                        $wire.$parent.$refresh();
                    }
                }, 500);
            "
            x-bind:disabled="checking"
            class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-color-custom fi-btn-color-primary fi-size-md gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-custom-600 text-white hover:bg-custom-500 dark:bg-custom-500 dark:hover:bg-custom-400 focus-visible:ring-custom-500/50 dark:focus-visible:ring-custom-400/50"
            style="--c-400: var(--primary-400); --c-500: var(--primary-500); --c-600: var(--primary-600);"
        >
            <template x-if="!checking">
                <span>{{ __('lead-pipeline::lead-pipeline.facebook.connect') }}</span>
            </template>
            <template x-if="checking">
                <span>{{ __('lead-pipeline::lead-pipeline.facebook.connecting') }}</span>
            </template>
        </button>
    @endif
</div>
