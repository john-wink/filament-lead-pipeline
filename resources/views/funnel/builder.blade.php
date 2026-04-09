<div class="space-y-6">

    {{-- Flash Message --}}
    @if (session('message'))
        <div class="rounded-lg border border-success-200 bg-success-50 px-4 py-3 text-sm font-medium text-success-700 dark:border-success-700 dark:bg-success-900/20 dark:text-success-400">
            {{ session('message') }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">

        {{-- Meta Section --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('lead-pipeline::lead-pipeline.funnel.general') }}</h3>
            </div>
            <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2">
                <div class="fi-fo-field-wrp">
                    <label class="fi-fo-field-wrp-label mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Name <span class="text-danger-600">*</span>
                    </label>
                    <input
                        type="text"
                        wire:model="name"
                        class="fi-input block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        placeholder="{{ __('lead-pipeline::lead-pipeline.funnel.name_placeholder') }}"
                        required
                    >
                    @error('name')
                        <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="fi-fo-field-wrp">
                    <label class="fi-fo-field-wrp-label mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Slug <span class="text-danger-600">*</span>
                    </label>
                    <div class="flex rounded-lg border border-gray-300 shadow-sm focus-within:border-primary-500 focus-within:ring-1 focus-within:ring-primary-500 dark:border-gray-600">
                        @php $routePrefix = config('lead-pipeline.funnel.route_prefix', 'funnel'); @endphp
                        @if($routePrefix)
                            <span class="inline-flex items-center rounded-l-lg border-r border-gray-300 bg-gray-50 px-3 text-sm text-gray-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400">
                                /{{ $routePrefix }}/
                            </span>
                        @endif
                        <input
                            type="text"
                            wire:model.live="slug"
                            @class([
                                'block w-full bg-white px-3 py-2 text-sm text-gray-900 focus:outline-none dark:bg-gray-800 dark:text-white',
                                'rounded-r-lg' => (bool) $routePrefix,
                                'rounded-lg' => ! $routePrefix,
                            ])
                            placeholder="{{ __('lead-pipeline::lead-pipeline.funnel.slug_placeholder') }}"
                            required
                        >
                    </div>
                    @error('slug')
                        <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                    @enderror
                    @if ($funnel)
                        <div class="mt-1 flex items-center gap-1.5" x-data="{ copied: false }">
                            <span class="text-xs text-gray-500 dark:text-gray-400">URL:</span>
                            <a href="{{ $funnel->getPublicUrl() }}" target="_blank" class="text-xs font-mono text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 hover:underline">
                                {{ $funnel->getPublicUrl() }}
                            </a>
                            <button type="button"
                                x-on:click="navigator.clipboard.writeText('{{ $funnel->getPublicUrl() }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                class="inline-flex items-center rounded p-0.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                                title="{{ __('lead-pipeline::lead-pipeline.funnel.copy_url') }}"
                            >
                                <template x-if="!copied">
                                    <x-heroicon-o-clipboard-document class="w-3.5 h-3.5" />
                                </template>
                                <template x-if="copied">
                                    <x-heroicon-o-check class="w-3.5 h-3.5 text-success-500" />
                                </template>
                            </button>
                        </div>
                    @endif
                </div>

                <div class="fi-fo-field-wrp sm:col-span-2">
                    <label class="fi-fo-field-wrp-label mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('lead-pipeline::lead-pipeline.funnel.auto_assign_label') }}
                    </label>
                    <select wire:model="auto_assign_to"
                        class="fi-input block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        <option value="">{{ __('lead-pipeline::lead-pipeline.board.auto_move_none') }}</option>
                        @foreach(\JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin::getAssignableUsers() as $assignableUser)
                            <option value="{{ $assignableUser->getKey() }}">{{ $assignableUser->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.actions.auto_assign_help') }}</p>
                </div>
            </div>
        </div>

        {{-- Steps Section --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('lead-pipeline::lead-pipeline.funnel.steps_section') }}</h3>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ count($steps) }} {{ count($steps) === 1 ? __('lead-pipeline::lead-pipeline.funnel.step_count_single') : __('lead-pipeline::lead-pipeline.funnel.step_count_plural') }}</span>
            </div>

            <div class="space-y-4 p-6">

                @forelse ($steps as $stepIndex => $step)
                    <div class="rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-600 dark:bg-gray-800/50">
                        {{-- Step Header --}}
                        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-600">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                    {{ __('lead-pipeline::lead-pipeline.funnel.step_label', ['number' => $stepIndex + 1]) }}
                                </span>
                                @if(($step['step_type'] ?? 'form') === 'intro')
                                    <span class="inline-flex items-center rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-400">Intro</span>
                                @endif
                            </div>
                            <button
                                type="button"
                                wire:click="removeStep({{ $stepIndex }})"
                                wire:confirm="{{ __('lead-pipeline::lead-pipeline.funnel.step_remove_confirm') }}"
                                class="rounded-md p-1 text-gray-400 transition hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/20 dark:hover:text-danger-400"
                                title="{{ __('lead-pipeline::lead-pipeline.funnel.step_remove') }}"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div class="space-y-4 p-4">
                            {{-- Step Name + Description --}}
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.step_name') }} <span class="text-danger-600">*</span></label>
                                    <input
                                        type="text"
                                        wire:model="steps.{{ $stepIndex }}.name"
                                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                        placeholder="{{ __('lead-pipeline::lead-pipeline.funnel.step_label', ['number' => $stepIndex + 1]) }}"
                                        required
                                    >
                                    @error("steps.{$stepIndex}.name")
                                        <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.step_description') }}</label>
                                    <textarea
                                        wire:model="steps.{{ $stepIndex }}.description"
                                        rows="1"
                                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                        placeholder="{{ __('lead-pipeline::lead-pipeline.funnel.description_placeholder') }}"
                                    ></textarea>
                                </div>
                            </div>

                            {{-- Step Type + Visibility Toggles --}}
                            <div style="display: flex; gap: 1.5rem; flex-wrap: wrap;">
                                <label class="inline-flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400 cursor-pointer">
                                    <select wire:model.live="steps.{{ $stepIndex }}.step_type"
                                        class="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs text-gray-900 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                                        <option value="form">{{ __('lead-pipeline::lead-pipeline.funnel.step_type_form') }}</option>
                                        <option value="intro">{{ __('lead-pipeline::lead-pipeline.funnel.step_type_intro') }}</option>
                                    </select>
                                </label>
                                <label class="inline-flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400 cursor-pointer">
                                    <input type="checkbox" wire:model="steps.{{ $stepIndex }}.show_name"
                                        class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800">
                                    {{ __('lead-pipeline::lead-pipeline.funnel.step_show_name') }}
                                </label>
                                <label class="inline-flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400 cursor-pointer">
                                    <input type="checkbox" wire:model="steps.{{ $stepIndex }}.show_description"
                                        class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800">
                                    {{ __('lead-pipeline::lead-pipeline.funnel.step_show_description') }}
                                </label>
                            </div>

                            {{-- Rejection Rules --}}
                            @if(($step['step_type'] ?? 'form') !== 'intro')
                                <div class="mt-3">
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.rejection_rules') }}</label>
                                        <button type="button" wire:click="addRejectionRule({{ $stepIndex }})"
                                            class="text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">
                                            {{ __('lead-pipeline::lead-pipeline.funnel.rejection_rules_add') }}
                                        </button>
                                    </div>
                                    @foreach(($step['rejection_rules'] ?? []) as $ruleIndex => $rule)
                                        <div class="flex items-center gap-2 mb-2">
                                            <select wire:model="steps.{{ $stepIndex }}.rejection_rules.{{ $ruleIndex }}.field_key"
                                                class="flex-1 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                                                <option value="">{{ __('lead-pipeline::lead-pipeline.funnel.rejection_rule_field') }}</option>
                                                @foreach($this->getAvailableFieldDefinitions() as $def)
                                                    <option value="{{ $def->key }}">{{ $def->name }}</option>
                                                @endforeach
                                            </select>
                                            <select wire:model="steps.{{ $stepIndex }}.rejection_rules.{{ $ruleIndex }}.operator"
                                                class="w-20 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                                                <option value="=">=</option>
                                                <option value="!=">!=</option>
                                                <option value="<">&lt;</option>
                                                <option value=">">&gt;</option>
                                                <option value="<=">&lt;=</option>
                                                <option value=">=">&gt;=</option>
                                                <option value="contains">{{ __('lead-pipeline::lead-pipeline.funnel.rejection_rule_contains') }}</option>
                                                <option value="in">{{ __('lead-pipeline::lead-pipeline.funnel.rejection_rule_in_list') }}</option>
                                            </select>
                                            <input type="text" wire:model="steps.{{ $stepIndex }}.rejection_rules.{{ $ruleIndex }}.value"
                                                placeholder="{{ __('lead-pipeline::lead-pipeline.funnel.rejection_rule_value') }}"
                                                class="flex-1 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-primary-500 focus:ring-1 focus:ring-primary-500" />
                                            <button type="button" wire:click="removeRejectionRule({{ $stepIndex }}, {{ $ruleIndex }})"
                                                class="shrink-0 rounded-md p-1 text-gray-400 hover:text-danger-600 dark:hover:text-danger-400">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                    @endforeach
                                    @if(empty($step['rejection_rules']))
                                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('lead-pipeline::lead-pipeline.funnel.rejection_rules_empty') }}</p>
                                    @endif
                                </div>
                            @endif

                            {{-- Fields (hidden for intro steps) --}}
                            @if (($step['step_type'] ?? 'form') === 'intro')
                                <div class="rounded-lg border border-dashed border-gray-300 px-4 py-3 text-center dark:border-gray-600">
                                    <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('lead-pipeline::lead-pipeline.funnel.step_intro_hint') }}</p>
                                </div>
                            @elseif (! empty($step['fields']))
                                <div class="space-y-3">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.fields_section') }}</p>

                                    @foreach ($step['fields'] as $fieldIndex => $field)
                                        @php
                                            $allowedTypes = ! empty($field['definition_id'])
                                                ? $this->getAllowedFunnelTypes($field['definition_id'])
                                                : [];
                                            $selectedType = $field['funnel_field_type'] ?? '';
                                            $hasOptions = $selectedType !== '' && \JohnWink\FilamentLeadPipeline\Enums\FunnelFieldTypeEnum::tryFrom($selectedType)?->hasOptions();
                                            $isSlider = $selectedType === \JohnWink\FilamentLeadPipeline\Enums\FunnelFieldTypeEnum::Slider->value;
                                        @endphp

                                        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-600 dark:bg-gray-800">
                                            {{-- Field Row 1: Definition + Type + Required --}}
                                            <div class="flex flex-wrap items-start gap-3">
                                                {{-- Board Field Select --}}
                                                <div class="min-w-0 flex-1">
                                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.field_board_field') }} <span class="text-danger-600">*</span></label>
                                                    <select
                                                        wire:model.live="steps.{{ $stepIndex }}.fields.{{ $fieldIndex }}.definition_id"
                                                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                                    >
                                                        <option value="">{{ __('lead-pipeline::lead-pipeline.funnel.field_select_placeholder') }}</option>
                                                        @foreach ($this->getAvailableFieldDefinitions() as $definition)
                                                            <option value="{{ $definition->getKey() }}" @selected($field['definition_id'] == $definition->getKey())>
                                                                {{ $definition->name }} ({{ $definition->type->getLabel() }})
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    @error("steps.{$stepIndex}.fields.{$fieldIndex}.definition_id")
                                                        <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                                                    @enderror
                                                </div>

                                                {{-- Funnel Type Select --}}
                                                <div class="min-w-0 flex-1">
                                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.field_type') }} <span class="text-danger-600">*</span></label>
                                                    <select
                                                        wire:model.live="steps.{{ $stepIndex }}.fields.{{ $fieldIndex }}.funnel_field_type"
                                                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                                        @disabled(empty($field['definition_id']))
                                                    >
                                                        <option value="">{{ __('lead-pipeline::lead-pipeline.funnel.field_type_placeholder') }}</option>
                                                        @foreach ($allowedTypes as $typeValue => $typeLabel)
                                                            <option value="{{ $typeValue }}" @selected($field['funnel_field_type'] === $typeValue)>
                                                                {{ $typeLabel }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    @error("steps.{$stepIndex}.fields.{$fieldIndex}.funnel_field_type")
                                                        <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                                                    @enderror
                                                </div>

                                                {{-- Required Toggle + Remove --}}
                                                <div class="flex items-end gap-2 pt-5">
                                                    <label class="flex cursor-pointer items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                                                        <input
                                                            type="checkbox"
                                                            wire:model="steps.{{ $stepIndex }}.fields.{{ $fieldIndex }}.is_required"
                                                            class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600"
                                                        >
                                                        Pflicht
                                                    </label>
                                                    <button
                                                        type="button"
                                                        wire:click="removeField({{ $stepIndex }}, {{ $fieldIndex }})"
                                                        class="rounded-md p-1.5 text-gray-400 transition hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/20 dark:hover:text-danger-400"
                                                        title="Feld entfernen"
                                                    >
                                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>

                                            {{-- Field Row 2: Placeholder + Help Text --}}
                                            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                                <div>
                                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Platzhalter</label>
                                                    <input
                                                        type="text"
                                                        wire:model="steps.{{ $stepIndex }}.fields.{{ $fieldIndex }}.placeholder"
                                                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                                        placeholder="z.B. Max Mustermann"
                                                    >
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.field_help_text') }}</label>
                                                    <input
                                                        type="text"
                                                        wire:model="steps.{{ $stepIndex }}.fields.{{ $fieldIndex }}.help_text"
                                                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                                        placeholder="{{ __('lead-pipeline::lead-pipeline.funnel.field_help_placeholder') }}"
                                                    >
                                                </div>
                                            </div>

                                            {{-- Options Editor (for option_cards / multi_option_cards / icon_cards) --}}
                                            @if ($hasOptions && ! $isSlider)
                                                <div class="mt-3">
                                                    <div class="flex items-center justify-between">
                                                        <label class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.field_options') }}</label>
                                                        <button
                                                            type="button"
                                                            wire:click="addOption({{ $stepIndex }}, {{ $fieldIndex }})"
                                                            class="text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300"
                                                        >
                                                            {{ __('lead-pipeline::lead-pipeline.funnel.field_option_add') }}
                                                        </button>
                                                    </div>
                                                    <div class="mt-2 space-y-2">
                                                        @foreach (($field['funnel_options'] ?? []) as $optionIndex => $option)
                                                            <div class="flex items-center gap-2">
                                                                <input
                                                                    type="text"
                                                                    wire:model="steps.{{ $stepIndex }}.fields.{{ $fieldIndex }}.funnel_options.{{ $optionIndex }}.label"
                                                                    class="block flex-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                                                    placeholder="{{ __('lead-pipeline::lead-pipeline.funnel.field_option_label') }}"
                                                                >
                                                                <input
                                                                    type="text"
                                                                    wire:model="steps.{{ $stepIndex }}.fields.{{ $fieldIndex }}.funnel_options.{{ $optionIndex }}.value"
                                                                    class="block flex-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                                                    placeholder="{{ __('lead-pipeline::lead-pipeline.funnel.field_option_value') }}"
                                                                >
                                                                <button
                                                                    type="button"
                                                                    wire:click="removeOption({{ $stepIndex }}, {{ $fieldIndex }}, {{ $optionIndex }})"
                                                                    class="shrink-0 rounded-md p-1 text-gray-400 hover:text-danger-600 dark:hover:text-danger-400"
                                                                >
                                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                                                    </svg>
                                                                </button>
                                                            </div>
                                                        @endforeach
                                                        @if (empty($field['funnel_options']))
                                                            <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('lead-pipeline::lead-pipeline.funnel.field_options_empty') }}</p>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endif

                                            {{-- Slider Options --}}
                                            @if ($hasOptions && $isSlider)
                                                <div class="mt-3">
                                                    <label class="mb-2 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.field_slider_settings') }}</label>
                                                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                                        <div>
                                                            <label class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Min</label>
                                                            <input
                                                                type="number"
                                                                wire:model="steps.{{ $stepIndex }}.fields.{{ $fieldIndex }}.funnel_options.min"
                                                                class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                                                placeholder="0"
                                                            >
                                                        </div>
                                                        <div>
                                                            <label class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Max</label>
                                                            <input
                                                                type="number"
                                                                wire:model="steps.{{ $stepIndex }}.fields.{{ $fieldIndex }}.funnel_options.max"
                                                                class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                                                placeholder="100"
                                                            >
                                                        </div>
                                                        <div>
                                                            <label class="mb-1 block text-xs text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.field_slider_step') }}</label>
                                                            <input
                                                                type="number"
                                                                wire:model="steps.{{ $stepIndex }}.fields.{{ $fieldIndex }}.funnel_options.step"
                                                                class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                                                placeholder="1"
                                                            >
                                                        </div>
                                                        <div>
                                                            <label class="mb-1 block text-xs text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.field_slider_unit') }}</label>
                                                            <input
                                                                type="text"
                                                                wire:model="steps.{{ $stepIndex }}.fields.{{ $fieldIndex }}.funnel_options.unit"
                                                                class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                                                placeholder="€"
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if(($step['step_type'] ?? 'form') !== 'intro')
                                <button
                                    type="button"
                                    wire:click="addField({{ $stepIndex }})"
                                    class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:border-primary-400 hover:bg-primary-50 hover:text-primary-600 dark:border-gray-600 dark:text-gray-400 dark:hover:border-primary-500 dark:hover:bg-primary-900/20 dark:hover:text-primary-400"
                                >
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                    {{ __('lead-pipeline::lead-pipeline.funnel.field_add') }}
                                </button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-gray-300 px-6 py-10 text-center dark:border-gray-600">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.step_no_steps') }}</p>
                    </div>
                @endforelse

                @error('steps')
                    <p class="text-xs text-danger-600">{{ $message }}</p>
                @enderror

                <button
                    type="button"
                    wire:click="addStep"
                    class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-primary-300 px-4 py-3 text-sm font-semibold text-primary-600 transition hover:border-primary-400 hover:bg-primary-50 dark:border-primary-700 dark:text-primary-400 dark:hover:bg-primary-900/20"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    {{ __('lead-pipeline::lead-pipeline.funnel.step_add') }}
                </button>
            </div>
        </div>

        {{-- Design Section --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('lead-pipeline::lead-pipeline.funnel.design') }}</h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">

                    {{-- Colors --}}
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.background_color') }}</label>
                        <div class="flex items-center gap-3">
                            <input type="color" wire:model="background_color" class="h-9 w-16 cursor-pointer rounded-lg border border-gray-300 p-0.5 dark:border-gray-600">
                            <input type="text" wire:model="background_color" class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-mono text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.primary_color') }}</label>
                        <div class="flex items-center gap-3">
                            <input type="color" wire:model="primary_color" class="h-9 w-16 cursor-pointer rounded-lg border border-gray-300 p-0.5 dark:border-gray-600">
                            <input type="text" wire:model="primary_color" class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-mono text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.text_color') }}</label>
                        <div class="flex items-center gap-3">
                            <input type="color" wire:model="text_color" class="h-9 w-16 cursor-pointer rounded-lg border border-gray-300 p-0.5 dark:border-gray-600">
                            <input type="text" wire:model="text_color" class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-mono text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.font_family') }}</label>
                        <input
                            type="text"
                            wire:model="font_family"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            placeholder="Inter, system-ui, sans-serif"
                        >
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.border_radius') }}</label>
                        <input
                            type="text"
                            wire:model="border_radius"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            placeholder="12px"
                        >
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.max_width') }}</label>
                        <input
                            type="text"
                            wire:model="max_width"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            placeholder="540px"
                        >
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.logo_position') }}</label>
                        <select
                            wire:model="logo_position"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        >
                            <option value="left">{{ __('lead-pipeline::lead-pipeline.funnel.logo_position_left') }}</option>
                            <option value="center">{{ __('lead-pipeline::lead-pipeline.funnel.logo_position_center') }}</option>
                            <option value="right">{{ __('lead-pipeline::lead-pipeline.funnel.logo_position_right') }}</option>
                        </select>
                    </div>

                    <div class="flex flex-col gap-3 pt-1">
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input
                                type="checkbox"
                                wire:model="show_progress_bar"
                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600"
                            >
                            {{ __('lead-pipeline::lead-pipeline.funnel.show_progress_bar_label') }}
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input
                                type="checkbox"
                                wire:model="show_step_numbers"
                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600"
                            >
                            {{ __('lead-pipeline::lead-pipeline.funnel.show_step_numbers_label') }}
                        </label>
                    </div>

                </div>

                <div class="mt-5 space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.logo_url') }}</label>
                        <input
                            type="url"
                            wire:model="logo_url"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            placeholder="https://..."
                        >
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.favicon_url') }}</label>
                        <input
                            type="url"
                            wire:model="favicon_url"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            placeholder="https://..."
                        >
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.background_image') }}</label>
                        <input
                            type="url"
                            wire:model="background_image"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            placeholder="https://..."
                        >
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.custom_css') }}</label>
                        <textarea
                            wire:model="custom_css"
                            rows="4"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            placeholder=".funnel-card { ... }"
                        ></textarea>
                    </div>
                </div>
            </div>
        </div>

        {{-- Success Section --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('lead-pipeline::lead-pipeline.funnel.success_section') }}</h3>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.success_section_desc') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2">

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.success_heading_label') }}</label>
                    <input
                        type="text"
                        wire:model="success_heading"
                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        placeholder="{{ __('lead-pipeline::lead-pipeline.funnel.success_heading') }}"
                    >
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.success_text_label') }}</label>
                    <textarea
                        wire:model="success_text"
                        rows="2"
                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        placeholder="{{ __('lead-pipeline::lead-pipeline.funnel.success_text') }}"
                    ></textarea>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.success_redirect_label') }}</label>
                    <input
                        type="url"
                        wire:model="success_redirect_url"
                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        placeholder="https://..."
                    >
                    @error('success_redirect_url')
                        <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.success_calendar_label') }} <span class="text-xs font-normal text-gray-400">(e.g. Calendly)</span></label>
                    <input
                        type="url"
                        wire:model="success_calendar_embed"
                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        placeholder="https://calendly.com/..."
                    >
                    @error('success_calendar_embed')
                        <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

            </div>
        </div>

        {{-- Rejection Section --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('lead-pipeline::lead-pipeline.funnel.rejection_section') }}</h3>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ __('lead-pipeline::lead-pipeline.funnel.rejection_section_desc') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.rejection_heading_label') }}</label>
                    <input type="text" wire:model="rejection_heading"
                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        placeholder="{{ __('lead-pipeline::lead-pipeline.funnel.rejection_heading') }}" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.rejection_text_label') }}</label>
                    <textarea wire:model="rejection_text" rows="2"
                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        placeholder="{{ __('lead-pipeline::lead-pipeline.funnel.rejection_text') }}"></textarea>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('lead-pipeline::lead-pipeline.funnel.rejection_redirect_label') }}</label>
                    <input type="url" wire:model="rejection_redirect_url"
                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        placeholder="https://..." />
                </div>
            </div>
        </div>

        {{-- Action Bar --}}
        <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white px-6 py-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            @if ($funnel)
                <a
                    href="{{ $funnel->getPublicUrl() }}"
                    target="_blank"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                    </svg>
                    {{ __('lead-pipeline::lead-pipeline.funnel.preview') }}
                </a>
            @else
                <span></span>
            @endif

            <button
                type="submit"
                class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:bg-primary-500 dark:hover:bg-primary-400"
            >
                <span wire:loading.remove wire:target="save">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                </span>
                <span wire:loading wire:target="save">
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </span>
                <span wire:loading.remove wire:target="save">{{ __('lead-pipeline::lead-pipeline.funnel.save') }}</span>
                <span wire:loading wire:target="save">{{ __('lead-pipeline::lead-pipeline.funnel.saving') }}</span>
            </button>
        </div>

    </form>
</div>
