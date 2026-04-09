<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Filament\Forms\Components\Component;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadFunnelStepFieldFactory;
use JohnWink\FilamentLeadPipeline\Enums\FunnelFieldTypeEnum;

class LeadFunnelStepField extends Model
{
    use HasConfigurablePrimaryKey;
    use HasFactory;

    protected $table = 'lead_funnel_step_fields';

    protected $fillable = [
        'lead_funnel_step_uuid',
        'lead_funnel_step_id',
        'lead_field_definition_uuid',
        'lead_field_definition_id',
        'sort',
        'is_required',
        'placeholder',
        'help_text',
        'funnel_field_type',
        'funnel_options',
        'settings',
    ];

    public function step(): BelongsTo
    {
        return $this->belongsTo(LeadFunnelStep::class, static::fkColumn('lead_funnel_step'));
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(LeadFieldDefinition::class, static::fkColumn('lead_field_definition'));
    }

    /**
     * Creates a Filament form component with funnel-specific settings.
     */
    public function toFormComponent(): Component
    {
        $component = $this->definition->toFormComponent();

        if ($this->is_required) {
            $component->required();
        }

        if ($this->placeholder) {
            $component->placeholder($this->placeholder);
        }

        if ($this->help_text) {
            $component->helperText($this->help_text);
        }

        return $component;
    }

    protected static function newFactory(): LeadFunnelStepFieldFactory
    {
        return LeadFunnelStepFieldFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort'              => 'integer',
            'is_required'       => 'boolean',
            'funnel_field_type' => FunnelFieldTypeEnum::class,
            'funnel_options'    => 'array',
            'settings'          => 'array',
        ];
    }
}
