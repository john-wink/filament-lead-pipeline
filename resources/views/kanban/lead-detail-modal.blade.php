<div>
    @if($isOpen && $lead)
        {{-- Backdrop --}}
        <div class="fixed inset-0 z-40 bg-black/50 backdrop-blur-sm transition-opacity" x-data @click="$wire.closeModal()"></div>

        {{-- Slide-over panel --}}
        <div class="fixed inset-y-0 right-0 z-50 w-full max-w-lg overflow-y-auto bg-white shadow-2xl dark:bg-gray-900"
            x-data="{ showLostReason: false, lostReason: '' }"
            x-transition:enter="transform transition ease-in-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transform transition ease-in-out duration-300"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full">

            {{-- Header --}}
            <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-center gap-3">
                    {{-- Initials avatar --}}
                    <div class="lead-avatar-lg lead-avatar"
                        style="background-color: {{ $lead->phase->color ?? '#6B7280' }}">
                        {{ mb_strtoupper(mb_substr($lead->name, 0, 1)) }}
                    </div>
                    <div>
                        {{-- Editable name --}}
                        <div x-data="{ editing: false }" class="group">
                            <h2 x-show="!editing"
                                @click="editing = true; $nextTick(() => $refs.nameInput.focus())"
                                class="cursor-pointer text-lg font-semibold text-gray-900 dark:text-gray-100 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
                                title="{{ __('lead-pipeline::lead-pipeline.field.click_to_edit') }}">
                                {{ $lead->name }}
                            </h2>
                            <input x-show="editing" x-cloak
                                x-ref="nameInput"
                                type="text"
                                value="{{ $lead->name }}"
                                @blur="$wire.updateField('name', $el.value); editing = false"
                                @keydown.enter="$el.blur()"
                                @keydown.escape="editing = false"
                                class="w-full rounded border-gray-300 text-lg font-semibold dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 focus:border-primary-500 focus:ring-primary-500" />
                        </div>
                        <span @class([
                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' => $lead->status === \JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum::Active,
                            'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' => $lead->status === \JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum::Won,
                            'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' => $lead->status === \JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum::Lost,
                            'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200' => $lead->status === \JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum::Converted,
                        ])>
                            {{ $lead->status->getLabel() }}
                        </span>
                    </div>
                </div>
                <button @click="$wire.closeModal()" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300 transition-colors">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            <div class="space-y-6 p-6">
                {{-- Contact Info --}}
                <div class="space-y-2">
                    {{-- Editable email --}}
                    <div class="flex items-center gap-2.5 text-sm text-gray-600 dark:text-gray-400" x-data="{ editing: false }">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-800">
                            <x-heroicon-o-envelope class="h-4 w-4" />
                        </div>
                        <div class="flex-1" x-show="!editing"
                            @click="editing = true; $nextTick(() => $refs.emailInput.focus())"
                            class="cursor-pointer hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
                            title="{{ __('lead-pipeline::lead-pipeline.field.click_to_edit') }}">
                            @if($lead->email)
                                <a href="mailto:{{ $lead->email }}" @click.stop>{{ $lead->email }}</a>
                            @else
                                <span class="italic text-gray-400">{{ __('lead-pipeline::lead-pipeline.field.add_email') }}</span>
                            @endif
                        </div>
                        <input x-show="editing" x-cloak
                            x-ref="emailInput"
                            type="email"
                            value="{{ $lead->email }}"
                            placeholder="{{ __('lead-pipeline::lead-pipeline.field.enter_email') }}"
                            @blur="$wire.updateField('email', $el.value); editing = false"
                            @keydown.enter="$el.blur()"
                            @keydown.escape="editing = false"
                            class="flex-1 rounded border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 focus:border-primary-500 focus:ring-primary-500" />
                    </div>

                    {{-- Editable phone --}}
                    <div class="flex items-center gap-2.5 text-sm text-gray-600 dark:text-gray-400" x-data="{ editing: false }">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-800">
                            <x-heroicon-o-phone class="h-4 w-4" />
                        </div>
                        <div class="flex-1" x-show="!editing"
                            @click="editing = true; $nextTick(() => $refs.phoneInput.focus())"
                            class="cursor-pointer hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
                            title="{{ __('lead-pipeline::lead-pipeline.field.click_to_edit') }}">
                            @if($lead->phone)
                                <a href="tel:{{ $lead->phone }}" @click.stop>{{ $lead->phone }}</a>
                            @else
                                <span class="italic text-gray-400">{{ __('lead-pipeline::lead-pipeline.field.add_phone') }}</span>
                            @endif
                        </div>
                        <input x-show="editing" x-cloak
                            x-ref="phoneInput"
                            type="tel"
                            value="{{ $lead->phone }}"
                            placeholder="{{ __('lead-pipeline::lead-pipeline.field.enter_phone') }}"
                            @blur="$wire.updateField('phone', $el.value); editing = false"
                            @keydown.enter="$el.blur()"
                            @keydown.escape="editing = false"
                            class="flex-1 rounded border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 focus:border-primary-500 focus:ring-primary-500" />
                    </div>
                </div>

                <hr class="lead-section-divider" />

                {{-- Value & Phase --}}
                <div class="grid grid-cols-2 gap-4">
                    {{-- Editable value --}}
                    <div class="rounded-xl bg-gray-50 p-3.5 dark:bg-gray-800" x-data="{ editing: false }">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.field.value') }}</p>
                        <div x-show="!editing"
                            @click="editing = true; $nextTick(() => $refs.valueInput.focus())"
                            class="cursor-pointer hover:text-primary-600 transition-colors"
                            title="{{ __('lead-pipeline::lead-pipeline.field.click_to_edit') }}">
                            @if($lead->value)
                                <p class="text-lg font-bold text-emerald-600 dark:text-emerald-400">
                                    {{ number_format((float) $lead->value, 0, ',', '.') }} &euro;
                                </p>
                            @else
                                <p class="text-sm italic text-gray-400">{{ __('lead-pipeline::lead-pipeline.field.enter_value') }}</p>
                            @endif
                        </div>
                        <input x-show="editing" x-cloak
                            x-ref="valueInput"
                            type="number"
                            min="0"
                            step="1"
                            value="{{ $lead->value }}"
                            placeholder="0"
                            @blur="$wire.updateField('value', $el.value); editing = false"
                            @keydown.enter="$el.blur()"
                            @keydown.escape="editing = false"
                            class="w-full rounded border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:border-primary-500 focus:ring-primary-500" />
                    </div>

                    {{-- Phase select --}}
                    @if($lead->phase)
                        <div class="rounded-xl bg-gray-50 p-3.5 dark:bg-gray-800">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.phase.singular') }}</p>
                            <select wire:change="changePhase($event.target.value)"
                                class="mt-0.5 w-full rounded border-gray-200 text-sm font-semibold dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:ring-1 focus:ring-primary-500">
                                @foreach($lead->board->phases()->ordered()->get() as $phase)
                                    <option value="{{ $phase->getKey() }}" @selected($lead->phase->getKey() === $phase->getKey())>
                                        {{ $phase->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>

                {{-- Assigned User --}}
                <div class="rounded-xl bg-gray-50 p-3.5 dark:bg-gray-800">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('lead-pipeline::lead-pipeline.field.assigned_to') }}</p>
                    <select wire:change="assignUser($event.target.value)"
                        class="w-full text-sm rounded border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:ring-1 focus:ring-primary-500">
                        <option value="">{{ __('lead-pipeline::lead-pipeline.field.not_assigned') }}</option>
                        @foreach(\JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin::getAssignableUsers() as $assignableUser)
                            <option value="{{ $assignableUser->getKey() }}" @selected($lead->assigned_to === $assignableUser->getKey())>
                                {{ $assignableUser->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Timestamps --}}
                <div class="flex items-center gap-4 text-xs text-gray-400 dark:text-gray-500">
                    <div class="flex items-center gap-1">
                        <x-heroicon-o-clock class="h-3.5 w-3.5" />
                        <span>{{ __('lead-pipeline::lead-pipeline.lead.created_at', ['date' => $lead->created_at->format('d.m.Y H:i')]) }}</span>
                    </div>
                    @if($lead->updated_at && !$lead->updated_at->eq($lead->created_at))
                        <div class="flex items-center gap-1">
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                            <span>{{ __('lead-pipeline::lead-pipeline.lead.updated_at', ['date' => $lead->updated_at->diffForHumans()]) }}</span>
                        </div>
                    @endif
                </div>

                <hr class="lead-section-divider" />

                {{-- Custom Field Values --}}
                @if($lead->fieldValues->isNotEmpty())
                    <div>
                        <h3 class="mb-2.5 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('lead-pipeline::lead-pipeline.field.fields') }}</h3>
                        <div class="space-y-1.5">
                            @foreach($lead->fieldValues as $fieldValue)
                                @if($fieldValue->definition)
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-800">
                                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ $fieldValue->definition->name }}</span>
                                        <div x-data="{ editing: false }">
                                            @php
                                                $defType = $fieldValue->definition->type;
                                                $defId = $fieldValue->definition->getKey();
                                                $defOptions = $fieldValue->definition->options ?? [];
                                            @endphp

                                            @if($defType === \JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::Boolean)
                                                <select
                                                    wire:change="updateCustomField('{{ $defId }}', $event.target.value)"
                                                    class="rounded border-gray-200 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:ring-1 focus:ring-primary-500">
                                                    <option value="0" @selected(!$fieldValue->casted_value)>{{ __('lead-pipeline::lead-pipeline.field.no') }}</option>
                                                    <option value="1" @selected((bool)$fieldValue->casted_value)>{{ __('lead-pipeline::lead-pipeline.field.yes') }}</option>
                                                </select>
                                            @elseif($defType === \JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::Select)
                                                <select
                                                    wire:change="updateCustomField('{{ $defId }}', $event.target.value)"
                                                    class="rounded border-gray-200 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:ring-1 focus:ring-primary-500">
                                                    <option value="">--</option>
                                                    @foreach($defOptions as $optKey => $optVal)
                                                        @if(is_array($optVal))
                                                            <option value="{{ $optVal['value'] ?? $optKey }}" @selected(($fieldValue->casted_value ?? '') === ($optVal['value'] ?? $optKey))>
                                                                {{ $optVal['label'] ?? $optVal['value'] ?? $optKey }}
                                                            </option>
                                                        @else
                                                            <option value="{{ $optKey }}" @selected(($fieldValue->casted_value ?? '') === (string) $optKey)>
                                                                {{ $optVal }}
                                                            </option>
                                                        @endif
                                                    @endforeach
                                                </select>
                                            @elseif($defType === \JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::Textarea)
                                                <div x-show="!editing"
                                                    @click="editing = true; $nextTick(() => $refs.customTextarea{{ $loop->index }}.focus())"
                                                    class="cursor-pointer text-xs text-gray-900 dark:text-gray-100 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
                                                    title="{{ __('lead-pipeline::lead-pipeline.field.click_to_edit') }}">
                                                    {{ $fieldValue->display_value ?: '...' }}
                                                </div>
                                                <textarea x-show="editing" x-cloak
                                                    x-ref="customTextarea{{ $loop->index }}"
                                                    rows="2"
                                                    @blur="$wire.updateCustomField('{{ $defId }}', $el.value); editing = false"
                                                    @keydown.escape="editing = false"
                                                    class="w-full rounded border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:border-primary-500 focus:ring-primary-500">{{ $fieldValue->casted_value ?? '' }}</textarea>
                                            @elseif($defType === \JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::Date)
                                                <input type="date"
                                                    value="{{ $fieldValue->casted_value ?? '' }}"
                                                    @change="$wire.updateCustomField('{{ $defId }}', $el.value)"
                                                    class="rounded border-gray-200 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:ring-1 focus:ring-primary-500" />
                                            @else
                                                @php
                                                    $inputType = match($defType) {
                                                        \JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::Email => 'email',
                                                        \JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::Phone => 'tel',
                                                        \JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::Number => 'number',
                                                        \JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::Decimal,
                                                        \JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::Currency => 'number',
                                                        \JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::Url => 'url',
                                                        default => 'text',
                                                    };
                                                @endphp
                                                <div x-show="!editing"
                                                    @click="editing = true; $nextTick(() => $refs.customInput{{ $loop->index }}.focus())"
                                                    class="cursor-pointer text-xs text-gray-900 dark:text-gray-100 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
                                                    title="{{ __('lead-pipeline::lead-pipeline.field.click_to_edit') }}">
                                                    {{ $fieldValue->display_value ?: '...' }}
                                                </div>
                                                <input x-show="editing" x-cloak
                                                    x-ref="customInput{{ $loop->index }}"
                                                    type="{{ $inputType }}"
                                                    value="{{ is_array($fieldValue->casted_value) ? implode(', ', $fieldValue->casted_value) : $fieldValue->casted_value }}"
                                                    @blur="$wire.updateCustomField('{{ $defId }}', $el.value); editing = false"
                                                    @keydown.enter="$el.blur()"
                                                    @keydown.escape="editing = false"
                                                    class="rounded border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:border-primary-500 focus:ring-primary-500" />
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    <hr class="lead-section-divider" />
                @endif

                {{-- Action Buttons --}}
                <div class="flex gap-2">
                    @if($lead->status === \JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum::Active)
                        <button wire:click="markAsWon" wire:confirm="Lead als gewonnen markieren?"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-green-300 bg-white px-3 py-2 text-xs font-medium text-green-600 hover:bg-green-50 dark:border-green-700 dark:bg-gray-800 dark:text-green-400 dark:hover:bg-green-900/20 transition-colors">
                            <x-heroicon-o-check-circle class="h-4 w-4" />
                            {{ __('lead-pipeline::lead-pipeline.actions.mark_won') }}
                        </button>

                        <button
                            x-show="!showLostReason"
                            @click="showLostReason = true"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-medium text-red-600 hover:bg-red-50 dark:border-red-700 dark:bg-gray-800 dark:text-red-400 dark:hover:bg-red-900/20 transition-colors">
                            <x-heroicon-o-x-circle class="h-4 w-4" />
                            {{ __('lead-pipeline::lead-pipeline.actions.mark_lost') }}
                        </button>

                        {{-- Lost reason form --}}
                        <div x-show="showLostReason" x-cloak x-transition class="w-full space-y-2">
                            <label class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.actions.reason_optional') }}</label>
                            <textarea
                                x-model="lostReason"
                                rows="2"
                                placeholder="{{ __('lead-pipeline::lead-pipeline.actions.lost_reason_placeholder') }}"
                                class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 focus:border-red-500 focus:ring-red-500 placeholder:text-gray-400"></textarea>
                            <div class="flex gap-2">
                                <button
                                    @click="$wire.markAsLost(lostReason); showLostReason = false; lostReason = '';"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 transition-colors">
                                    <x-heroicon-o-x-circle class="h-3.5 w-3.5" />
                                    {{ __('lead-pipeline::lead-pipeline.actions.confirm') }}
                                </button>
                                <button
                                    @click="showLostReason = false; lostReason = '';"
                                    class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 transition-colors">
                                    {{ __('lead-pipeline::lead-pipeline.actions.cancel') }}
                                </button>
                            </div>
                        </div>
                    @endif
                </div>

                <hr class="lead-section-divider" />

                {{-- Add Note --}}
                <div>
                    <h3 class="mb-2.5 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('lead-pipeline::lead-pipeline.actions.add_note') }}</h3>
                    <div class="flex gap-2">
                        <textarea wire:model="newNote"
                            rows="2"
                            placeholder="{{ __('lead-pipeline::lead-pipeline.actions.note_placeholder') }}"
                            class="flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 focus:border-primary-500 focus:ring-primary-500 placeholder:text-gray-400 transition-colors"></textarea>
                        <button wire:click="addNote"
                            wire:loading.attr="disabled"
                            class="self-end rounded-lg bg-primary-600 px-3 py-2 text-xs font-medium text-white hover:bg-primary-700 disabled:opacity-50 transition-colors">
                            <span wire:loading.remove wire:target="addNote">{{ __('lead-pipeline::lead-pipeline.actions.send') }}</span>
                            <span wire:loading wire:target="addNote">
                                <svg class="inline h-3.5 w-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </span>
                        </button>
                    </div>
                </div>

                {{-- Activity Timeline --}}
                @if($lead->activities && $lead->activities->isNotEmpty())
                    <hr class="lead-section-divider" />

                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('lead-pipeline::lead-pipeline.activity.timeline') }}</h3>
                        <div class="max-h-80 space-y-3 overflow-y-auto pr-1" style="scrollbar-width: thin; scrollbar-color: rgba(156, 163, 175, 0.3) transparent;">
                            @foreach($lead->activities as $activity)
                                <div class="flex gap-3">
                                    <div class="flex flex-col items-center">
                                        <div @class([
                                            'flex h-7 w-7 items-center justify-center rounded-full',
                                            'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-400' => $activity->type === \JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum::Created,
                                            'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400' => $activity->type === \JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum::Moved,
                                            'bg-purple-100 text-purple-600 dark:bg-purple-900 dark:text-purple-400' => $activity->type === \JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum::Updated,
                                            'bg-yellow-100 text-yellow-600 dark:bg-yellow-900 dark:text-yellow-400' => $activity->type === \JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum::Converted,
                                            'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400' => $activity->type === \JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum::Note,
                                            'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-400' => $activity->type === \JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum::Call,
                                            'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400' => $activity->type === \JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum::Email,
                                            'bg-indigo-100 text-indigo-600 dark:bg-indigo-900 dark:text-indigo-400' => $activity->type === \JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum::Assignment,
                                        ])>
                                            <x-dynamic-component :component="$activity->type->getIcon()" class="h-3.5 w-3.5" />
                                        </div>
                                        @if(!$loop->last)
                                            <div class="mt-1 h-full w-px bg-gray-200 dark:bg-gray-700"></div>
                                        @endif
                                    </div>
                                    <div class="flex-1 pb-3">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-medium text-gray-900 dark:text-gray-100">{{ $activity->type->getLabel() }}</span>
                                            <span class="text-xs text-gray-400 dark:text-gray-500">{{ $activity->created_at->diffForHumans() }}</span>
                                        </div>
                                        <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ $activity->description }}</p>
                                        @if($activity->causer)
                                            <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">von {{ $activity->causer->name ?? 'System' }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
