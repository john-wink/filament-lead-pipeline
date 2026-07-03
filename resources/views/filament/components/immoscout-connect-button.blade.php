<?php
    $tenant = filament()->getTenant();

    $connection = \JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection::query()
        ->when($tenant, fn ($query) => $query->where('team_uuid', $tenant->getKey()))
        ->orderByDesc('updated_at')
        ->first();

    $isConnected = $connection && $connection->isConnected();
    $redirectUrl = route('lead-pipeline.immoscout.redirect', array_filter(['team' => $tenant?->getKey()]));
?>

<div
    x-data="{
        openOauth() {
            try { localStorage.removeItem('lead-pipeline:immoscout-connected'); } catch (e) {}

            const popup = window.open(
                @js($redirectUrl),
                'immoscout_connect',
                'width=600,height=760,scrollbars=yes,status=yes'
            );

            const finish = () => {
                if (this.pollInterval) { clearInterval(this.pollInterval); this.pollInterval = null; }
                window.removeEventListener('message', onMessage);
                window.removeEventListener('storage', onStorage);
                try { localStorage.removeItem('lead-pipeline:immoscout-connected'); } catch (e) {}
                if (window.Livewire && typeof $wire !== 'undefined') {
                    try { $wire.$refresh(); } catch (e) {}
                }
            };

            const onMessage = (event) => {
                if (event.data && 'immoscout-connected' === event.data.type) { finish(); }
            };

            const onStorage = (event) => {
                if ('lead-pipeline:immoscout-connected' === event.key && event.newValue) { finish(); }
            };

            window.addEventListener('message', onMessage);
            window.addEventListener('storage', onStorage);

            this.pollInterval = setInterval(() => {
                try {
                    if (localStorage.getItem('lead-pipeline:immoscout-connected')) { finish(); return; }
                } catch (e) {}
                if (popup && popup.closed) { finish(); }
            }, 1000);
        },
        pollInterval: null,
    }"
    class="flex items-center gap-3"
>
    <x-filament::button
        type="button"
        color="{{ $isConnected ? 'gray' : 'primary' }}"
        icon="heroicon-o-link"
        x-on:click="openOauth()"
    >
        @if ($isConnected)
            {{ __('lead-pipeline::lead-pipeline.immoscout.reconnect_button') }}
        @else
            {{ __('lead-pipeline::lead-pipeline.immoscout.connect_button') }}
        @endif
    </x-filament::button>

    @if ($isConnected)
        <x-filament::badge color="success">
            {{ __('lead-pipeline::lead-pipeline.immoscout.connected_badge', ['name' => $connection->name]) }}
        </x-filament::badge>
    @else
        <span class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('lead-pipeline::lead-pipeline.immoscout.connect_hint') }}
        </span>
    @endif
</div>
