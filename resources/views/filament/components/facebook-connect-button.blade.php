@php
    $connection = \JohnWink\FilamentLeadPipeline\Models\FacebookConnection::query()
        ->where('user_uuid', auth()->id())
        ->orderByDesc('updated_at')
        ->first();

    $isConnected = $connection && $connection->isConnected();
    $isExpired   = $connection && $connection->needsReauth();
    $redirectUrl   = route('lead-pipeline.facebook.redirect');
    $disconnectUrl = $connection ? route('lead-pipeline.facebook.disconnect', $connection) : null;
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

            const redirectUrl = new URL(@js($redirectUrl), window.location.href);
            redirectUrl.searchParams.set('opener_origin', window.location.origin);

            const popup = window.open(
                redirectUrl.toString(),
                'facebook_connect',
                'width=600,height=700,scrollbars=yes,status=yes'
            );

            let pollInterval = null;

            const finish = () => {
                if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
                window.removeEventListener('message', onMessage);
                try { localStorage.removeItem('lead-pipeline:facebook-connected'); } catch (e) {}
                this.refresh();
            };

            // Primary signal: cross-origin postMessage from the callback popup.
            // The callback runs on the fixed redirect-URI domain, not this
            // panel's subdomain, so localStorage cannot bridge the two origins.
            const onMessage = (event) => {
                if (event && event.data && event.data.type === 'facebook-connected') {
                    finish();
                }
            };
            window.addEventListener('message', onMessage);

            // Fallback: same-origin localStorage signal (fires only when the
            // callback shares this page's origin).
            pollInterval = setInterval(() => {
                const raw = (() => { try { return localStorage.getItem('lead-pipeline:facebook-connected'); } catch (e) { return null; } })();
                const signalTs = raw ? parseInt(raw, 10) : 0;

                if (signalTs > startedAt) { finish(); return; }

                if (Date.now() - startedAt > 180000) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    window.removeEventListener('message', onMessage);
                }
            }, 500);
        },

        async disconnect() {
            if (!confirm(@js(__('lead-pipeline::lead-pipeline.connection_status.disconnect_confirm')))) { return; }

            const token = document.querySelector('meta[name=csrf-token]')?.content
                ?? window.livewireScriptConfig?.csrf
                ?? '';

            try {
                const response = await fetch(@js($disconnectUrl), {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!response.ok) { throw new Error(response.statusText); }
            } catch (e) {
                console.error('lead-pipeline: facebook disconnect failed', e);
            }

            this.refresh();
        },
    }"
>
    @if($isConnected)
        <div class="flex flex-col gap-2">
            <div class="flex items-center gap-2 text-sm text-success-600 dark:text-success-400">
                <x-heroicon-o-check-circle class="w-5 h-5" />
                <span>{{ __('lead-pipeline::lead-pipeline.facebook.connected_as', ['name' => $connection->facebook_user_name]) }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    x-on:click="openOauth()"
                    class="rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800 transition-colors"
                >
                    {{ __('lead-pipeline::lead-pipeline.facebook.connect_other_account') }}
                </button>
                <button
                    type="button"
                    x-on:click="disconnect()"
                    class="rounded-lg border border-danger-300 px-2.5 py-1 text-xs font-medium text-danger-600 hover:bg-danger-50 dark:border-danger-800 dark:text-danger-400 dark:hover:bg-danger-900/20 transition-colors"
                >
                    {{ __('lead-pipeline::lead-pipeline.facebook.disconnect') }}
                </button>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.facebook.disconnect_hint') }}</p>
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
            <button
                type="button"
                x-on:click="disconnect()"
                class="self-start rounded-lg border border-danger-300 px-2.5 py-1 text-xs font-medium text-danger-600 hover:bg-danger-50 dark:border-danger-800 dark:text-danger-400 dark:hover:bg-danger-900/20 transition-colors"
            >
                {{ __('lead-pipeline::lead-pipeline.facebook.disconnect') }}
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
