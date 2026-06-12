<div class="space-y-3">
    @forelse($connections as $connection)
        @php $state = $connection->healthState(); @endphp
        <div class="rounded-xl border p-3.5 dark:bg-gray-800/50 {{ match($state) {
            'critical' => 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-900/20',
            'warning'  => 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/20',
            default    => 'border-gray-200 bg-white dark:border-gray-700',
        } }}">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span @class([
                            'inline-block h-2.5 w-2.5 shrink-0 rounded-full',
                            'bg-red-500'     => 'critical' === $state,
                            'bg-amber-500'   => 'warning' === $state,
                            'bg-emerald-500' => 'ok' === $state,
                        ])></span>
                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $connection->facebook_user_name }}</p>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('lead-pipeline::lead-pipeline.connection_status.pages_count', ['count' => $connection->pages_count]) }}
                        @if($connection->token_expires_at)
                            · {{ __('lead-pipeline::lead-pipeline.connection_status.expires_at', ['date' => $connection->token_expires_at->format('d.m.Y')]) }}
                        @endif
                        @if($connection->last_refreshed_at)
                            · {{ __('lead-pipeline::lead-pipeline.connection_status.last_refreshed', ['date' => $connection->last_refreshed_at->diffForHumans()]) }}
                        @endif
                    </p>
                    @if([] !== $connection->healthReasons())
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            @foreach($connection->healthReasons() as $reason)
                                <span class="rounded-full bg-white/70 px-2 py-0.5 text-xs font-medium text-gray-700 ring-1 ring-gray-200 dark:bg-gray-900/40 dark:text-gray-300 dark:ring-gray-700">
                                    {{ __('lead-pipeline::lead-pipeline.connection_status.reasons.' . $reason) }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="flex shrink-0 flex-col items-end gap-1.5">
                    <button type="button" wire:click="refreshToken('{{ $connection->getKey() }}')"
                        wire:loading.attr="disabled" wire:target="refreshToken"
                        class="rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800 transition-colors">
                        {{ __('lead-pipeline::lead-pipeline.connection_status.refresh_token') }}
                    </button>
                    <a href="{{ route('lead-pipeline.facebook.redirect') }}"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium text-white transition-colors {{ 'critical' === $state ? 'bg-red-600 hover:bg-red-700' : 'bg-primary-600 hover:bg-primary-700' }}">
                        {{ __('lead-pipeline::lead-pipeline.connection_status.reconnect') }}
                    </a>
                </div>
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.connection_status.no_connections') }}</p>
    @endforelse
</div>
