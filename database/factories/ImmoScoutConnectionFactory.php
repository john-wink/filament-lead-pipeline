<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutEnvironmentEnum;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;

/** @extends Factory<ImmoScoutConnection> */
class ImmoScoutConnectionFactory extends Factory
{
    protected $model = ImmoScoutConnection::class;

    public function definition(): array
    {
        return [
            'name'                => $this->faker->company() . ' IS24',
            'consumer_key'        => $this->faker->uuid(),
            'consumer_secret'     => $this->faker->sha1(),
            'access_token'        => $this->faker->uuid(),
            'access_token_secret' => $this->faker->sha1(),
            'scout_id'            => (string) $this->faker->numberBetween(10000000, 99999999),
            'environment'         => ImmoScoutEnvironmentEnum::Sandbox,
            'status'              => ImmoScoutConnectionStatusEnum::Connected,
        ];
    }

    public function production(): static
    {
        return $this->state(['environment' => ImmoScoutEnvironmentEnum::Production]);
    }

    public function twoLegged(): static
    {
        return $this->state([
            'access_token'        => null,
            'access_token_secret' => null,
        ]);
    }
}
