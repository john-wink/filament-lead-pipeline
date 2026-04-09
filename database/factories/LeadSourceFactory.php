<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

/**
 * @extends Factory<LeadSource>
 */
class LeadSourceFactory extends Factory
{
    protected $model = LeadSource::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lead_board_uuid' => LeadBoard::factory(),
            'name'            => $this->faker->words(2, true),
            'driver'          => LeadSourceTypeEnum::Api,
            'status'          => LeadSourceStatusEnum::Draft,
            'config'          => [],
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LeadSourceStatusEnum::Active,
        ]);
    }

    public function withApiToken(): static
    {
        return $this->state(fn (array $attributes): array => [
            'api_token'      => Str::random(64),
            'webhook_secret' => Str::random(32),
        ]);
    }

    public function zapier(): static
    {
        return $this->state(fn (array $attributes): array => [
            'driver' => LeadSourceTypeEnum::Zapier,
            'name'   => 'Zapier Integration',
        ]);
    }

    public function meta(): static
    {
        return $this->state(fn (array $attributes): array => [
            'driver' => LeadSourceTypeEnum::Meta,
            'name'   => 'Facebook / Meta Leads',
        ]);
    }

    public function funnel(): static
    {
        return $this->state(fn (array $attributes): array => [
            'driver' => LeadSourceTypeEnum::Funnel,
            'name'   => 'Funnel Source',
        ]);
    }

    public function withDefaultAssignee(string $userId): static
    {
        return $this->state(['default_assigned_to' => $userId]);
    }
}
