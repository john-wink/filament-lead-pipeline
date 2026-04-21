<div
    x-data="{ checking: false }"
    x-init="
        const reloadOnConnect = () => { window.location.reload(); };

        // Same-window popup message (works when popup keeps an opener)
        window.addEventListener('message', (event) => {
            if (event.origin !== window.location.origin) return;
            if (event.data && event.data.type === 'facebook-connected') {
                reloadOnConnect();
            }
        });

        // Cross-tab signal (works when browser opened the OAuth flow in a new tab or COOP blocked the opener)
        window.addEventListener('storage', (event) => {
            if (event.key === 'lead-pipeline:facebook-connected' && event.newValue) {
                reloadOnConnect();
            }
        });
    "
>
    @php
        $connection = \JohnWink\FilamentLeadPipeline\Models\FacebookConnection::query()
            ->where('user_uuid', auth()->id())
            ->orderByDesc('updated_at')
            ->first();

        $isConnected = $connection && $connection->status === 'connected' && ! $connection->isExpired();
        $isExpired   = $connection && ($connection->status === 'expired' || $connection->isExpired());

        $openPopup = "
            checking = true;

            // Clear any previous signal so the storage listener only fires for the new attempt.
            try { localStorage.removeItem('lead-pipeline:facebook-connected'); } catch (e) {}

            const popup = window.open(
                '" . route('lead-pipeline.facebook.redirect') . "',
                'facebook_connect',
                'width=600,height=700,scrollbars=yes,status=yes'
            );

            // Fallback: if the popup is closed (manual cancel or auth completed without signals reaching us), do a hard reload so the form reflects whatever state is now persisted.
            const interval = setInterval(() => {
                if (!popup || popup.closed) {
                    clearInterval(interval);
                    checking = false;
                    window.location.reload();
                }
            }, 500);
        ";
    @endphp

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
                x-on:click="{{ $openPopup }}"
                x-bind:disabled="checking"
                class="self-start fi-btn fi-btn-size-sm relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-size-sm gap-1.5 px-3 py-1.5 text-sm inline-grid shadow-sm bg-warning-600 text-white hover:bg-warning-500 dark:bg-warning-500 dark:hover:bg-warning-400"
            >
                <template x-if="!checking">
                    <span>{{ __('lead-pipeline::lead-pipeline.facebook.reconnect') }}</span>
                </template>
                <template x-if="checking">
                    <span>{{ __('lead-pipeline::lead-pipeline.facebook.connecting') }}</span>
                </template>
            </button>
        </div>
    @else
        <button
            type="button"
            x-on:click="{{ $openPopup }}"
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
