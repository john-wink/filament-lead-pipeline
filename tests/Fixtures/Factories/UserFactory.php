<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\User;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name'  => $this->faker->lastName(),
            'email'      => $this->faker->unique()->safeEmail(),
            'password'   => bcrypt('password'),
        ];
    }
}
