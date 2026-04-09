<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Models\FacebookForm;

class FacebookFormFactory extends Factory
{
    protected $model = FacebookForm::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'form_id'   => $this->faker->numerify('form-####'),
            'form_name' => $this->faker->sentence(3),
            'status'    => 'active',
            'cached_at' => now(),
        ];
    }
}
