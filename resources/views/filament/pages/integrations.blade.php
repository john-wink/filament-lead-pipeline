<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-2">
        @foreach($this->getIntegrations() as $integration)
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-800">
                        <x-dynamic-component :component="$integration->icon()" class="h-5 w-5 text-gray-600 dark:text-gray-300" />
                    </div>
                    <h2 class="flex-1 text-base font-semibold text-gray-900 dark:text-gray-100">{{ $integration->label() }}</h2>
                    @if($this->isIntegrationActivated($integration))
                        <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-200">
                            {{ __('lead-pipeline::lead-pipeline.integrations.active') }}
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800 dark:bg-gray-700 dark:text-gray-200">
                            {{ __('lead-pipeline::lead-pipeline.integrations.inactive') }}
                        </span>
                    @endif
                </div>

                @php($settingsComponent = $this->resolveSettingsComponent($integration))
                @if($settingsComponent)
                    <div class="mt-4">
                        @livewire($settingsComponent, key('integration-settings-' . $integration->key()))
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
