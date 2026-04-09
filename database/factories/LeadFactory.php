<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lead_board_uuid' => LeadBoard::factory(),
            'lead_phase_uuid' => LeadPhase::factory(),
            'name'            => $this->faker->name(),
            'email'           => $this->faker->safeEmail(),
            'phone'           => $this->faker->phoneNumber(),
            'status'          => LeadStatusEnum::Active,
            'sort'            => 0,
            'raw_data'        => null,
        ];
    }

    public function won(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LeadStatusEnum::Won,
        ]);
    }

    public function lost(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status'      => LeadStatusEnum::Lost,
            'lost_at'     => now(),
            'lost_reason' => $this->faker->sentence(),
        ]);
    }

    public function converted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status'       => LeadStatusEnum::Converted,
            'converted_at' => now(),
        ]);
    }

    public function withValue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'value' => $this->faker->randomFloat(2, 1000, 500000),
        ]);
    }
}
