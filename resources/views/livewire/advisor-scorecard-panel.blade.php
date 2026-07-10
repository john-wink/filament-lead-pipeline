<div>
    @if ($isOpen && $card)
        <div class="fixed inset-0 z-50 flex justify-end" x-transition>
            <div class="fixed inset-0 bg-gray-900/50" wire:click="close"></div>
            <div class="relative w-full max-w-3xl overflow-y-auto bg-white shadow-xl dark:bg-gray-900">
                <div class="sticky top-0 border-b border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex items-start justify-between">
                        <div>
                            <h2 class="text-lg font-bold">{{ $card['row']['advisor_name'] ?? '—' }}</h2>
                            @if ($card['rank'])
                                <p class="text-sm text-gray-500">{{ __('lead-pipeline::lead-pipeline.operations.rank') }} {{ $card['rank'] }} / {{ $card['total_advisors'] }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-3">
                            @if ($card['row'])
                                <span class="rounded-full bg-primary-50 px-3 py-1 text-xl font-bold text-primary-700 dark:bg-primary-950 dark:text-primary-300">{{ number_format($card['row']['scores']['total'], 1, ',', '.') }}</span>
                            @endif
                            <button wire:click="close" class="text-gray-400 hover:text-gray-600">✕</button>
                        </div>
                    </div>
                    @if ($card['row'])
                        <div class="mt-3 grid grid-cols-2 gap-2 md:grid-cols-4">
                            @foreach (['activity' => 'score_activity', 'tempo' => 'score_tempo', 'result' => 'score_result', 'diligence' => 'score_diligence'] as $key => $langKey)
                                <div>
                                    <div class="flex justify-between text-xs text-gray-500">
                                        <span>{{ __('lead-pipeline::lead-pipeline.operations.' . $langKey) }}</span>
                                        <span class="tabular-nums">{{ number_format($card['row']['scores'][$key], 0, ',', '.') }}</span>
                                    </div>
                                    <div class="mt-1 h-1.5 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                                        <div class="h-full rounded-full bg-primary-500" style="width: {{ min($card['row']['scores'][$key], 100) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex gap-4 text-xs text-gray-500">
                            @if (null !== $card['row']['delta_score'])
                                <span>{{ __('lead-pipeline::lead-pipeline.operations.vs_previous') }}:
                                    <span class="{{ $card['row']['delta_score'] >= 0 ? 'text-primary-600' : 'text-red-600' }}">{{ $card['row']['delta_score'] >= 0 ? '+' : '' }}{{ number_format($card['row']['delta_score'], 1, ',', '.') }}</span></span>
                            @endif
                            <span>{{ __('lead-pipeline::lead-pipeline.operations.vs_team') }}:
                                @php($teamDiff = round($card['row']['scores']['total'] - $card['team']['score_avg'], 1))
                                <span class="{{ $teamDiff >= 0 ? 'text-primary-600' : 'text-red-600' }}">{{ $teamDiff >= 0 ? '+' : '' }}{{ number_format($teamDiff, 1, ',', '.') }}</span></span>
                        </div>
                    @endif
                </div>

                <div class="p-4">
                    <h3 class="mb-2 text-sm font-semibold">{{ __('lead-pipeline::lead-pipeline.operations.protocol_title') }}</h3>
                    @forelse ($protocol['days'] as $day)
                        <div class="mb-4" wire:key="proto-day-{{ $day['date'] }}">
                            <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $day['label'] }}</div>
                            <ul class="space-y-1">
                                @foreach ($day['items'] as $item)
                                    <li class="flex items-start gap-2 border-t border-gray-100 py-1.5 text-sm dark:border-gray-800">
                                        <span class="w-10 shrink-0 tabular-nums text-gray-400">{{ $item['time'] }}</span>
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium {{ match ($item['color']) {
                                            'success' => 'bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300',
                                            'danger' => 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
                                            'warning' => 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
                                            'info' => 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
                                            default => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
                                        } }}">{{ $item['type_label'] }}</span>
                                        <span class="font-medium">{{ $item['lead_name'] }}</span>
                                        <span class="text-gray-500">{{ $item['description'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400">{{ __('lead-pipeline::lead-pipeline.operations.no_activities') }}</p>
                    @endforelse

                    @if ($protocol['has_more'])
                        <x-filament::button size="sm" color="gray" wire:click="loadMore">{{ __('lead-pipeline::lead-pipeline.operations.load_more') }}</x-filament::button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
