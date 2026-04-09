<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnel;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStep;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

/**
 * @extends Factory<LeadFunnel>
 */
class LeadFunnelFactory extends Factory
{
    protected $model = LeadFunnel::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lead_source_uuid'  => LeadSource::factory(),
            'lead_board_uuid'   => LeadBoard::factory(),
            'name'              => $this->faker->words(3, true),
            'slug'              => $this->faker->unique()->slug(2),
            'design'            => config('lead-pipeline.funnel.default_design', []),
            'success_config'    => [],
            'is_active'         => true,
            'views_count'       => 0,
            'submissions_count' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function withSteps(): static
    {
        return $this->afterCreating(function (LeadFunnel $funnel): void {
            LeadFunnelStep::factory()
                ->count(3)
                ->sequence(
                    ['name' => 'Kontaktdaten', 'sort' => 0],
                    ['name' => 'Details', 'sort' => 1],
                    ['name' => 'Zusammenfassung', 'sort' => 2],
                )
                ->for($funnel, 'funnel')
                ->create();
        });
    }
}
