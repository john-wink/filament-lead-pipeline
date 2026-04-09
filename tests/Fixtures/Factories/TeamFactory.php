<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\Team;

class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'slug' => $this->faker->unique()->slug(2),
        ];
    }
}
