<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

/**
 * @extends Factory<LeadBoard>
 */
class LeadBoardFactory extends Factory
{
    protected $model = LeadBoard::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name'        => implode(' ', $this->faker->words(3)),
            'description' => $this->faker->optional()->sentence(),
            'settings'    => [],
            'is_active'   => true,
            'sort'        => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function withDefaultPhases(): static
    {
        return $this->afterCreating(function (LeadBoard $board): void {
            $phases = [
                ['name' => 'Neu', 'type' => LeadPhaseTypeEnum::Open, 'color' => '#6B7280', 'sort' => 0],
                ['name' => 'Kontaktiert', 'type' => LeadPhaseTypeEnum::InProgress, 'color' => '#3B82F6', 'sort' => 1],
                ['name' => 'Qualifiziert', 'type' => LeadPhaseTypeEnum::InProgress, 'color' => '#8B5CF6', 'sort' => 2],
                ['name' => 'Angebot', 'type' => LeadPhaseTypeEnum::InProgress, 'color' => '#F59E0B', 'sort' => 3],
                ['name' => 'Gewonnen', 'type' => LeadPhaseTypeEnum::Won, 'color' => '#10B981', 'sort' => 4],
                ['name' => 'Verloren', 'type' => LeadPhaseTypeEnum::Lost, 'color' => '#EF4444', 'sort' => 5],
            ];

            foreach ($phases as $phase) {
                $board->phases()->create($phase);
            }
        });
    }

    /**
     * System-Felder (Name, E-Mail, Telefon) werden jetzt automatisch
     * via LeadBoard::booted() created-Event erstellt. Diese Methode
     * bleibt als No-Op für Abwärtskompatibilität.
     */
    public function withSystemFields(): static
    {
        return $this;
    }
}
