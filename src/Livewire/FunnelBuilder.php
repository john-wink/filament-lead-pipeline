<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Livewire;

use Illuminate\Contracts\View\View;
use JohnWink\FilamentLeadPipeline\Enums\FunnelFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnel;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStep;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStepField;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use Livewire\Component;
use Throwable;

class FunnelBuilder extends Component
{
    public string $sourceId;

    public ?LeadFunnel $funnel = null;

    public ?LeadBoard $board = null;

    // Meta
    public string $name = '';

    public string $slug = '';

    // Steps
    public array $steps = [];

    // Design
    public string $background_color = '#ffffff';

    public string $primary_color = '#3B82F6';

    public string $text_color = '#1F2937';

    public string $font_family = 'Inter, system-ui, sans-serif';

    public string $border_radius = '12px';

    public string $max_width = '540px';

    public string $logo_position = 'center';

    public bool $show_progress_bar = true;

    public bool $show_step_numbers = false;

    public string $background_image = '';

    public string $logo_url = '';

    public string $favicon_url = '';

    public string $custom_css = '';

    // Success
    public string $success_heading = '';

    public string $success_text = '';

    public string $success_redirect_url = '';

    public string $success_calendar_embed = '';

    // Rejection
    public string $rejection_heading = '';

    public string $rejection_text = '';

    public string $rejection_redirect_url = '';

    public string $auto_assign_to = '';

    public function mount(string $sourceId): void
    {
        $this->sourceId          = $sourceId;
        $this->success_heading   = __('lead-pipeline::lead-pipeline.funnel.success_heading');
        $this->success_text      = __('lead-pipeline::lead-pipeline.funnel.success_text');
        $this->rejection_heading = __('lead-pipeline::lead-pipeline.funnel.rejection_heading');
        $this->rejection_text    = __('lead-pipeline::lead-pipeline.funnel.rejection_text');

        $source       = LeadSource::with('funnel.steps.fields.definition', 'board.fieldDefinitions')->findOrFail($sourceId);
        $this->board  = $source->board;
        $this->funnel = $source->funnel;

        if ($this->funnel) {
            $this->name = $this->funnel->name;
            $this->slug = $this->funnel->slug;

            // Panel defaults as base, saved design overrides them
            $panelDefaults = $this->resolvePanelDesignDefaults();
            $saved         = array_filter($this->funnel->design ?? [], fn ($v) => filled($v));
            $design        = array_merge($panelDefaults, $saved);

            foreach ($design as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->{$key} = $value;
                }
            }

            $success = $this->funnel->success_config ?? [];
            foreach ($success as $key => $value) {
                $prop = "success_{$key}";
                if (property_exists($this, $prop)) {
                    $this->{$prop} = $value;
                }
            }

            $rejection = $this->funnel->rejection_config ?? [];
            foreach ($rejection as $key => $value) {
                $prop = "rejection_{$key}";
                if (property_exists($this, $prop)) {
                    $this->{$prop} = $value;
                }
            }

            $this->auto_assign_to = $this->funnel->design['auto_assign_to'] ?? '';

            $this->steps = $this->funnel->steps->sortBy('sort')->map(fn (LeadFunnelStep $step) => [
                'id'               => $step->getKey(),
                'name'             => $step->name,
                'step_type'        => $step->step_type ?? 'form',
                'description'      => $step->description ?? '',
                'show_name'        => $step->showName(),
                'show_description' => $step->showDescription(),
                'rejection_rules'  => $step->settings['rejection_rules'] ?? [],
                'fields'           => $step->fields->sortBy('sort')->map(fn (LeadFunnelStepField $field) => [
                    'id'                => $field->getKey(),
                    'definition_id'     => $field->definition?->getKey(),
                    'funnel_field_type' => $field->funnel_field_type?->value ?? '',
                    'funnel_options'    => $field->funnel_options ?? [],
                    'is_required'       => $field->is_required,
                    'placeholder'       => $field->placeholder ?? '',
                    'help_text'         => $field->help_text ?? '',
                ])->values()->toArray(),
            ])->values()->toArray();
        }
    }

    public function getAvailableFieldDefinitions(): \Illuminate\Support\Collection
    {
        return $this->board?->fieldDefinitions()->ordered()->get() ?? collect();
    }

    public function getAllowedFunnelTypes(string $definitionId): array
    {
        $definition = LeadFieldDefinition::find($definitionId);
        if ( ! $definition) {
            return [];
        }

        return collect(FunnelFieldTypeEnum::allowedFor($definition->type))
            ->mapWithKeys(fn (FunnelFieldTypeEnum $type) => [$type->value => $type->getLabel()])
            ->toArray();
    }

    public function addStep(string $type = 'form'): void
    {
        $this->steps[] = [
            'name'             => 'intro' === $type ? __('lead-pipeline::lead-pipeline.funnel.welcome') : __('lead-pipeline::lead-pipeline.funnel.step', ['number' => count($this->steps) + 1]),
            'step_type'        => $type,
            'description'      => '',
            'show_name'        => true,
            'show_description' => true,
            'rejection_rules'  => [],
            'fields'           => [],
        ];
    }

    public function removeStep(int $index): void
    {
        array_splice($this->steps, $index, 1);
    }

    public function addField(int $stepIndex): void
    {
        $this->steps[$stepIndex]['fields'][] = [
            'definition_id'     => '',
            'funnel_field_type' => '',
            'funnel_options'    => [],
            'is_required'       => false,
            'placeholder'       => '',
            'help_text'         => '',
        ];
    }

    public function removeField(int $stepIndex, int $fieldIndex): void
    {
        array_splice($this->steps[$stepIndex]['fields'], $fieldIndex, 1);
    }

    public function addOption(int $stepIndex, int $fieldIndex): void
    {
        $this->steps[$stepIndex]['fields'][$fieldIndex]['funnel_options'][] = ['label' => '', 'value' => ''];
    }

    public function removeOption(int $stepIndex, int $fieldIndex, int $optionIndex): void
    {
        array_splice($this->steps[$stepIndex]['fields'][$fieldIndex]['funnel_options'], $optionIndex, 1);
    }

    public function addRejectionRule(int $stepIndex): void
    {
        $this->steps[$stepIndex]['rejection_rules'][] = ['field_key' => '', 'operator' => '=', 'value' => ''];
    }

    public function removeRejectionRule(int $stepIndex, int $ruleIndex): void
    {
        array_splice($this->steps[$stepIndex]['rejection_rules'], $ruleIndex, 1);
    }

    public function save(): void
    {
        $rules = [
            'name'         => 'required|string|max:255',
            'slug'         => 'required|string|max:255|alpha_dash',
            'steps'        => 'required|array|min:1',
            'steps.*.name' => 'required|string|max:255',
        ];

        foreach ($this->steps as $i => $step) {
            if (($step['step_type'] ?? 'form') !== 'intro') {
                $rules["steps.{$i}.fields"]                     = 'required|array|min:1';
                $rules["steps.{$i}.fields.*.definition_id"]     = 'required|string';
                $rules["steps.{$i}.fields.*.funnel_field_type"] = 'required|string';
            }
        }

        $this->validate($rules);

        $design = [
            'background_color'  => $this->background_color,
            'primary_color'     => $this->primary_color,
            'text_color'        => $this->text_color,
            'font_family'       => $this->font_family,
            'border_radius'     => $this->border_radius,
            'max_width'         => $this->max_width,
            'logo_position'     => $this->logo_position,
            'show_progress_bar' => $this->show_progress_bar,
            'show_step_numbers' => $this->show_step_numbers,
            'background_image'  => $this->background_image,
            'logo_url'          => $this->logo_url,
            'favicon_url'       => $this->favicon_url,
            'custom_css'        => $this->custom_css,
            'auto_assign_to'    => $this->auto_assign_to,
        ];

        $successConfig = [
            'heading'        => $this->success_heading,
            'text'           => $this->success_text,
            'redirect_url'   => $this->success_redirect_url,
            'calendar_embed' => $this->success_calendar_embed,
        ];

        $rejectionConfig = [
            'heading'      => $this->rejection_heading,
            'text'         => $this->rejection_text,
            'redirect_url' => $this->rejection_redirect_url,
        ];

        $this->funnel->update([
            'name'             => $this->name,
            'slug'             => $this->slug,
            'design'           => $design,
            'success_config'   => $successConfig,
            'rejection_config' => $rejectionConfig,
        ]);

        $this->funnel->steps()->delete();

        $defFk = LeadFieldDefinition::fkColumn('lead_field_definition');

        foreach ($this->steps as $stepIndex => $stepData) {
            $step = $this->funnel->steps()->create([
                'name'        => $stepData['name'],
                'step_type'   => $stepData['step_type'] ?? 'form',
                'description' => $stepData['description'] ?: null,
                'sort'        => $stepIndex,
                'settings'    => [
                    'show_name'        => $stepData['show_name'] ?? true,
                    'show_description' => $stepData['show_description'] ?? true,
                    'rejection_rules'  => $stepData['rejection_rules'] ?? [],
                ],
            ]);

            foreach ($stepData['fields'] as $fieldIndex => $fieldData) {
                $step->fields()->create([
                    $defFk              => $fieldData['definition_id'],
                    'sort'              => $fieldIndex,
                    'is_required'       => $fieldData['is_required'] ?? false,
                    'placeholder'       => $fieldData['placeholder'] ?: null,
                    'help_text'         => $fieldData['help_text'] ?: null,
                    'funnel_field_type' => $fieldData['funnel_field_type'],
                    'funnel_options'    => ! empty($fieldData['funnel_options']) ? $fieldData['funnel_options'] : null,
                ]);
            }
        }

        $this->dispatch('funnel-saved');
        session()->flash('message', 'Funnel erfolgreich gespeichert.');
    }

    public function render(): View
    {
        return view('lead-pipeline::funnel.builder');
    }

    /**
     * Reads design defaults from the current Filament panel.
     * Brand logo, favicon, and primary color are automatically applied.
     *
     * @return array<string, mixed>
     */
    protected function resolvePanelDesignDefaults(): array
    {
        $defaults = [];

        try {
            $panel = filament()->getCurrentPanel();
            if ( ! $panel) {
                return $defaults;
            }

            // Brand Logo → logo_url
            $logo = $panel->getBrandLogo();
            if (is_string($logo) && filled($logo)) {
                $defaults['logo_url'] = $logo;
            }

            // Favicon
            $favicon = $panel->getFavicon();
            if (filled($favicon)) {
                $defaults['favicon_url'] = $favicon;
            }

            // Primary Color → Hex aus dem 500er Shade
            $colors = $panel->getColors();
            if (isset($colors['primary'])) {
                $primary = $colors['primary'];

                if (is_string($primary)) {
                    $defaults['primary_color'] = $primary;
                } elseif (is_array($primary) && isset($primary[500])) {
                    $defaults['primary_color'] = $primary[500];
                }
            }
        } catch (Throwable) {
            // Graceful fallback
        }

        return array_filter($defaults);
    }
}
