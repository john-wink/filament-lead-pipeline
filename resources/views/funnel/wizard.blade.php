<div>
    @if($rejected)
        {{-- Rejection page --}}
        @include('lead-pipeline::funnel.rejection')
    @elseif(!$submitted)
        {{-- Progress bar --}}
        @if(($funnel->design['show_progress_bar'] ?? true) && $this->totalSteps > 1)
            <div style="margin-bottom: 2.5rem; text-align: center;">
                <span style="font-size: 0.875rem; font-weight: 500; color: var(--lp-text); opacity: 0.7;">{{ $this->progressPercentage }}%</span>
                <div style="height: 4px; background: var(--lp-progress-bg); border-radius: 999px; overflow: hidden; margin-top: 0.5rem;">
                    <div style="height: 100%; width: {{ $this->progressPercentage }}%; background: var(--lp-primary); border-radius: 999px; transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1);"></div>
                </div>
            </div>
        @endif

        {{-- Current step --}}
        @if($this->currentStepModel)
            <div wire:key="step-{{ $currentStep }}" style="animation: lp-fade-in 0.3s ease;">

                {{-- Step heading --}}
                @if($this->currentStepModel->showName())
                    <h2 style="font-size: clamp(1.5rem, 4vw, 2.25rem); font-weight: 700; line-height: 1.2; text-align: center; margin-bottom: 1rem; color: var(--lp-text);">
                        {{ $this->currentStepModel->name }}
                    </h2>
                @endif

                {{-- Step description --}}
                @if($this->currentStepModel->showDescription() && $this->currentStepModel->description)
                    <div style="text-align: center; margin-bottom: 2rem; font-size: 1rem; line-height: 1.7; color: var(--lp-text); opacity: 0.8;">
                        {!! nl2br(e($this->currentStepModel->description)) !!}
                    </div>
                @endif

                {{-- Fields (only for form steps) --}}
                @if(!$this->currentStepModel->isIntro())
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        @foreach($this->currentStepModel->fields as $field)
                            @php $key = $field->definition->key; $value = $formData[$key] ?? null; @endphp
                            @include($field->funnel_field_type->renderView(), ['field' => $field, 'key' => $key, 'value' => $value])
                        @endforeach
                    </div>
                @endif

                {{-- Validation errors --}}
                @if($errors->any())
                    <div style="margin-top: 1.25rem; padding: 0.875rem 1.25rem; background: rgba(220, 38, 38, 0.12); border: 1px solid rgba(220, 38, 38, 0.3); border-radius: var(--lp-radius); font-size: 0.875rem; color: var(--lp-error);">
                        @foreach($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                {{-- Navigation --}}
                <div style="display: flex; justify-content: center; align-items: center; margin-top: 2.5rem; gap: 0.75rem;">
                    @if($currentStep > 0)
                        <button wire:click="previousStep" type="button"
                            style="width: 52px; height: 52px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--lp-btn-border); border-radius: var(--lp-radius); background: var(--lp-btn-bg); cursor: pointer; color: var(--lp-text); transition: all 0.2s;"
                            onmouseover="this.style.background='var(--lp-btn-hover)';"
                            onmouseout="this.style.background='var(--lp-btn-bg)';">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M15 18l-6-6 6-6"/>
                            </svg>
                        </button>
                    @endif

                    @if($currentStep < $this->totalSteps - 1)
                        <button wire:click="nextStep" type="button"
                            wire:loading.attr="disabled" wire:target="nextStep"
                            style="padding: 0.875rem 2.5rem; background: var(--lp-primary); color: white; border: none; border-radius: var(--lp-radius); cursor: pointer; font-size: 1rem; font-weight: 600; font-family: inherit; transition: all 0.2s; min-width: 160px;"
                            onmouseover="this.style.opacity='0.9'; this.style.transform='translateY(-1px)';"
                            onmouseout="this.style.opacity='1'; this.style.transform='translateY(0)';"
                            wire:loading.style.add="opacity: 0.6; cursor: not-allowed;" wire:target="nextStep">
                            <span wire:loading.remove wire:target="nextStep">Weiter</span>
                            <span wire:loading wire:target="nextStep">Bitte warten&hellip;</span>
                        </button>
                    @else
                        <button wire:click="submit" type="button"
                            wire:loading.attr="disabled" wire:target="submit"
                            style="padding: 0.875rem 2.5rem; background: var(--lp-primary); color: white; border: none; border-radius: var(--lp-radius); cursor: pointer; font-size: 1rem; font-weight: 600; font-family: inherit; transition: all 0.2s; min-width: 160px;"
                            onmouseover="this.style.opacity='0.9'; this.style.transform='translateY(-1px)';"
                            onmouseout="this.style.opacity='1'; this.style.transform='translateY(0)';"
                            wire:loading.style.add="opacity: 0.6; cursor: not-allowed;" wire:target="submit">
                            <span wire:loading.remove wire:target="submit">Absenden</span>
                            <span wire:loading wire:target="submit">Wird gesendet&hellip;</span>
                        </button>
                    @endif
                </div>
            </div>
        @endif
    @else
        {{-- Success page --}}
        @include('lead-pipeline::funnel.success')
    @endif

    <style>
        @keyframes lp-fade-in {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    </style>
</div>
