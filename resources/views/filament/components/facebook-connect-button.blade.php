@php
    $connection = \JohnWink\FilamentLeadPipeline\Models\FacebookConnection::query()
        ->where('user_uuid', auth()->id())
        ->orderByDesc('updated_at')
        ->first();

    $isConnected = $connection && $connection->status === 'connected' && ! $connection->isExpired();
    $isExpired   = $connection && ($connection->status === 'expired' || $connection->isExpired());
    $redirectUrl = route('lead-pipeline.facebook.redirect');
@endphp

<div
    x-data="{
        refresh() {
            if (window.Livewire && typeof $wire !== 'undefined') {
                try { $wire.$refresh(); } catch (e) { /* ignore */ }
            }
        },

        openOauth() {
            try { localStorage.removeItem('lead-pipeline:facebook-connected'); } catch (e) {}

            const startedAt = Date.now();
            const popup = window.open(
                @js($redirectUrl),
                'facebook_connect',
                'width=600,height=700,scrollbars=yes,status=yes'
            );

            // Poll localStorage for the cross-tab signal from the callback.
            const pollInterval = setInterval(() => {
                const raw = (() => { try { return localStorage.getItem('lead-pipeline:facebook-connected'); } catch (e) { return null; } })();
                const signalTs = raw ? parseInt(raw, 10) : 0;

                if (signalTs > startedAt) {
                    clearInterval(pollInterval);
                    try { localStorage.removeItem('lead-pipeline:facebook-connected'); } catch (e) {}
                    this.refresh();
                    return;
                }

                // Safety net: stop polling after 3 minutes.
                if (Date.now() - startedAt > 180000) {
                    clearInterval(pollInterval);
                }
            }, 500);
        },
    }"
>
    @if($isConnected)
        <div class="flex items-center gap-2 text-sm text-success-600 dark:text-success-400">
            <x-heroicon-o-check-circle class="w-5 h-5" />
            <span>{{ __('lead-pipeline::lead-pipeline.facebook.connected_as', ['name' => $connection->facebook_user_name]) }}</span>
        </div>
    @elseif($isExpired)
        <div class="flex flex-col gap-2 rounded-lg border border-warning-300 bg-warning-50 p-3 dark:border-warning-600 dark:bg-warning-950/40">
            <div class="flex items-center gap-2 text-sm text-warning-700 dark:text-warning-300">
                <x-heroicon-o-exclamation-triangle class="w-5 h-5" />
                <span>{{ __('lead-pipeline::lead-pipeline.facebook.expired_warning', ['name' => $connection->facebook_user_name]) }}</span>
            </div>
            <button
                type="button"
                x-on:click="openOauth()"
                class="self-start fi-btn fi-btn-size-sm relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-size-sm gap-1.5 px-3 py-1.5 text-sm inline-grid shadow-sm bg-warning-600 text-white hover:bg-warning-500 dark:bg-warning-500 dark:hover:bg-warning-400"
            >
                {{ __('lead-pipeline::lead-pipeline.facebook.reconnect') }}
            </button>
        </div>
    @else
        <button
            type="button"
            x-on:click="openOauth()"
            class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-color-custom fi-btn-color-primary fi-size-md gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-custom-600 text-white hover:bg-custom-500 dark:bg-custom-500 dark:hover:bg-custom-400 focus-visible:ring-custom-500/50 dark:focus-visible:ring-custom-400/50"
            style="--c-400: var(--primary-400); --c-500: var(--primary-500); --c-600: var(--primary-600);"
        >
            {{ __('lead-pipeline::lead-pipeline.facebook.connect') }}
        </button>
    @endif
</div>
