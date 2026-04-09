<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

/**
 * @extends Factory<LeadPhase>
 */
class LeadPhaseFactory extends Factory
{
    protected $model = LeadPhase::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lead_board_uuid' => LeadBoard::factory(),
            'name'            => $this->faker->words(2, true),
            'color'           => $this->faker->hexColor(),
            'type'            => LeadPhaseTypeEnum::InProgress,
            'display_type'    => LeadPhaseDisplayTypeEnum::Kanban,
            'sort'            => 0,
            'auto_convert'    => false,
            'settings'        => [],
        ];
    }

    public function won(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type'  => LeadPhaseTypeEnum::Won,
            'name'  => 'Gewonnen',
            'color' => '#10B981',
        ]);
    }

    public function lost(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type'  => LeadPhaseTypeEnum::Lost,
            'name'  => 'Verloren',
            'color' => '#EF4444',
        ]);
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type'  => LeadPhaseTypeEnum::Open,
            'name'  => 'Neu',
            'color' => '#6B7280',
        ]);
    }
}
