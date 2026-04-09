<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;

/**
 * @extends Factory<LeadFieldDefinition>
 */
class LeadFieldDefinitionFactory extends Factory
{
    protected $model = LeadFieldDefinition::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'lead_board_uuid' => LeadBoard::factory(),
            'name'            => $name,
            'key'             => Str::slug($name, '_'),
            'type'            => LeadFieldTypeEnum::String,
            'options'         => null,
            'rules'           => null,
            'is_required'     => false,
            'is_system'       => false,
            'show_in_card'    => false,
            'show_in_funnel'  => true,
            'sort'            => 0,
            'settings'        => [],
        ];
    }

    public function required(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_required' => true,
        ]);
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_system' => true,
        ]);
    }

    public function emailField(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name'      => 'E-Mail',
            'key'       => 'email',
            'type'      => LeadFieldTypeEnum::Email,
            'is_system' => true,
        ]);
    }

    public function phoneField(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name'      => 'Telefon',
            'key'       => 'phone',
            'type'      => LeadFieldTypeEnum::Phone,
            'is_system' => true,
        ]);
    }
}
