<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Livewire;

use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnel;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStep;
use Livewire\Attributes\Computed;
use Livewire\Component;

class FunnelWizard extends Component
{
    public string $funnelId = '';

    public ?LeadFunnel $funnel = null;

    public int $currentStep = 0;

    /** @var array<string, mixed> */
    public array $formData = [];

    public bool $submitted = false;

    public bool $rejected = false;

    public function mount(string $funnelId): void
    {
        $this->funnelId = $funnelId;
        $this->funnel   = LeadFunnel::with(['steps' => fn ($q) => $q->orderBy('sort'), 'steps.fields' => fn ($q) => $q->orderBy('sort'), 'steps.fields.definition'])
            ->findOrFail($funnelId);
    }

    #[Computed]
    public function totalSteps(): int
    {
        if ($this->funnel && ! $this->funnel->relationLoaded('steps')) {
            $this->funnel->load(['steps' => fn ($q) => $q->orderBy('sort'), 'steps.fields.definition']);
        }

        return $this->funnel?->steps->count() ?? 0;
    }

    #[Computed]
    public function currentStepModel(): ?LeadFunnelStep
    {
        if ($this->funnel && ! $this->funnel->relationLoaded('steps')) {
            $this->funnel->load(['steps' => fn ($q) => $q->orderBy('sort'), 'steps.fields' => fn ($q) => $q->orderBy('sort'), 'steps.fields.definition']);
        }

        $step = $this->funnel?->steps->get($this->currentStep);

        if ($step && ! $step->relationLoaded('fields')) {
            $step->load(['fields' => fn ($q) => $q->orderBy('sort'), 'fields.definition']);
        }

        return $step;
    }

    #[Computed]
    public function progressPercentage(): int
    {
        if (0 === $this->totalSteps) {
            return 0;
        }

        return (int) round((($this->currentStep + 1) / $this->totalSteps) * 100);
    }

    public function nextStep(): void
    {
        $this->validateCurrentStep();

        if ($this->checkRejectionRules()) {
            $this->rejected = true;

            return;
        }

        $this->currentStep++;
        unset($this->currentStepModel, $this->progressPercentage);
    }

    public function previousStep(): void
    {
        $this->currentStep = max(0, $this->currentStep - 1);
        unset($this->currentStepModel, $this->progressPercentage);
    }

    public function submit(): void
    {
        $this->validateCurrentStep();
        $this->validateSystemFields();

        // Ensure necessary relations are loaded to avoid lazy loading violations
        if ( ! $this->funnel->relationLoaded('board')) {
            $this->funnel->load(['board.phases', 'source', 'steps.fields.definition']);
        }

        $fkBoard  = Lead::fkColumn('lead_board');
        $fkPhase  = Lead::fkColumn('lead_phase');
        $fkSource = Lead::fkColumn('lead_source');

        $lead = Lead::create([
            $fkBoard  => $this->funnel->board->getKey(),
            $fkPhase  => $this->funnel->targetPhase?->getKey() ?? $this->funnel->board->phases()->ordered()->first()?->getKey(),
            $fkSource => $this->funnel->source->getKey(),
            'name'    => $this->formData['name'] ?? '',
            'email'   => $this->formData['email'] ?? null,
            'phone'   => $this->formData['phone'] ?? null,
            'status'  => LeadStatusEnum::Active,
            'sort'    => Lead::query()->where($fkBoard, $this->funnel->board->getKey())->max('sort') + 1,
        ]);

        // Save custom field values
        foreach ($this->funnel->steps as $step) {
            foreach ($step->fields as $field) {
                $key = $field->definition->key;
                if (isset($this->formData[$key]) && ! in_array($key, ['name', 'email', 'phone'], true)) {
                    $value = null !== $field->funnel_field_type
                        ? $field->funnel_field_type->castValue($this->formData[$key])
                        : $this->formData[$key];
                    $lead->setFieldValue($field->definition, $value);
                }
            }
        }

        // Create activity log
        $lead->activities()->create([
            'type'        => LeadActivityTypeEnum::Created->value,
            'description' => sprintf('Lead created via funnel "%s"', $this->funnel->name),
            'properties'  => [
                'funnel_id'   => $this->funnel->getKey(),
                'funnel_name' => $this->funnel->name,
                'source'      => 'funnel',
            ],
        ]);

        // Auto-assign if configured
        $autoAssignTo = $this->funnel->design['auto_assign_to'] ?? null;
        if (filled($autoAssignTo)) {
            $lead->update(['assigned_to' => $autoAssignTo]);

            $userModel    = config('lead-pipeline.user_model');
            $assigneeName = $userModel::find($autoAssignTo)?->name ?? __('lead-pipeline::lead-pipeline.field.unknown');
            $lead->activities()->create([
                'type'        => LeadActivityTypeEnum::Assignment->value,
                'description' => "Automatically assigned to {$assigneeName} (Funnel)",
                'properties'  => ['funnel_id' => $this->funnel->getKey(), 'auto' => true],
            ]);

        }

        $this->funnel->incrementSubmissions();

        LeadCreated::dispatch($lead);

        $this->submitted = true;
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('lead-pipeline::funnel.wizard');
    }

    protected function checkRejectionRules(): bool
    {
        $step = $this->currentStepModel;
        if ( ! $step) {
            return false;
        }

        $rules = $step->settings['rejection_rules'] ?? [];
        if (empty($rules)) {
            return false;
        }

        foreach ($rules as $rule) {
            $fieldKey  = $rule['field_key'] ?? '';
            $operator  = $rule['operator'] ?? '=';
            $ruleValue = $rule['value'] ?? '';
            $formValue = $this->formData[$fieldKey] ?? null;

            if (null === $formValue || '' === $formValue) {
                continue;
            }

            $matches = match ($operator) {
                '='        => (string) $formValue === (string) $ruleValue,
                '!='       => (string) $formValue !== (string) $ruleValue,
                '<'        => (float) $formValue < (float) $ruleValue,
                '>'        => (float) $formValue > (float) $ruleValue,
                '<='       => (float) $formValue <= (float) $ruleValue,
                '>='       => (float) $formValue >= (float) $ruleValue,
                'contains' => str_contains((string) $formValue, (string) $ruleValue),
                'in'       => is_array($formValue) && in_array($ruleValue, $formValue, true),
                default    => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    protected function validateCurrentStep(): void
    {
        $step = $this->currentStepModel;

        if ( ! $step || $step->isIntro()) {
            return;
        }

        if ( ! $step->relationLoaded('fields')) {
            $step->load(['fields' => fn ($q) => $q->orderBy('sort'), 'fields.definition']);
        }

        $rules      = [];
        $messages   = [];
        $attributes = [];

        foreach ($step->fields as $field) {
            $definition = $field->definition;
            $key        = $definition->key;
            $isRequired = $field->is_required || $definition->is_required;

            $fieldRules = null !== $field->funnel_field_type
                ? $field->funnel_field_type->validationRules()
                : $definition->type->validationRules();

            if ($isRequired) {
                // For arrays (MultiOptionCards), use 'required|array|min:1' so a non-empty array is needed
                if (in_array('array', $fieldRules, true)) {
                    array_unshift($fieldRules, 'required');
                    $fieldRules[] = 'min:1';
                } else {
                    array_unshift($fieldRules, 'required');
                }
            } else {
                array_unshift($fieldRules, 'nullable');
            }

            $rules["formData.{$key}"] = $fieldRules;

            // Human-readable field name for error messages
            $attributes["formData.{$key}"] = $definition->name;
        }

        if ( ! empty($rules)) {
            $this->validate($rules, $messages, $attributes);
        }
    }

    /**
     * Validates that Name + (Email or Phone) are filled in.
     */
    protected function validateSystemFields(): void
    {
        $name  = $this->formData['name'] ?? '';
        $email = $this->formData['email'] ?? '';
        $phone = $this->formData['phone'] ?? '';

        $errors = [];

        if (blank($name)) {
            $errors['formData.name'] = __('lead-pipeline::lead-pipeline.validation.name_required');
        }

        if (blank($email) && blank($phone)) {
            $errors['formData.email'] = __('lead-pipeline::lead-pipeline.validation.email_or_phone');
            $errors['formData.phone'] = __('lead-pipeline::lead-pipeline.validation.email_or_phone');
        }

        if ( ! empty($errors)) {
            // Jump to the last step that contains a system field
            foreach ($this->funnel->steps->sortByDesc('sort') as $index => $step) {
                $hasSystemField = $step->fields->contains(fn ($f) => in_array($f->definition?->key, ['name', 'email', 'phone']));
                if ($hasSystemField) {
                    $this->currentStep = $this->funnel->steps->sortBy('sort')->values()->search(fn ($s) => $s->getKey() === $step->getKey());
                    break;
                }
            }

            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }
}
