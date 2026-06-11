<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Models\MetaAdCreative;

class MetaAdCreativeFactory extends Factory
{
    protected $model = MetaAdCreative::class;

    public function definition(): array
    {
        return [
            'ad_account_id' => 'act_' . $this->faker->numberBetween(100, 999),
            'campaign_id'   => 'c' . $this->faker->numberBetween(1, 99),
            'ad_id'         => 'ad_' . $this->faker->unique()->numberBetween(1000, 99999),
            'name'          => $this->faker->words(3, true),
            'status'        => 'ACTIVE',
            'image_path'    => null,
        ];
    }
}
