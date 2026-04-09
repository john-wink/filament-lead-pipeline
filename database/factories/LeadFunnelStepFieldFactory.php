<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Enums\FunnelFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStep;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStepField;

/**
 * @extends Factory<LeadFunnelStepField>
 */
class LeadFunnelStepFieldFactory extends Factory
{
    protected $model = LeadFunnelStepField::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lead_funnel_step_uuid'      => LeadFunnelStep::factory(),
            'lead_field_definition_uuid' => LeadFieldDefinition::factory(),
            'funnel_field_type'          => FunnelFieldTypeEnum::TextInput,
            'sort'                       => 0,
            'is_required'                => false,
            'placeholder'                => $this->faker->optional()->words(3, true),
            'help_text'                  => $this->faker->optional()->sentence(),
            'settings'                   => [],
        ];
    }
}
