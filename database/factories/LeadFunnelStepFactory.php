<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnel;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStep;

/**
 * @extends Factory<LeadFunnelStep>
 */
class LeadFunnelStepFactory extends Factory
{
    protected $model = LeadFunnelStep::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lead_funnel_uuid' => LeadFunnel::factory(),
            'name'             => $this->faker->words(2, true),
            'description'      => $this->faker->optional()->sentence(),
            'sort'             => 0,
            'settings'         => [],
        ];
    }
}
