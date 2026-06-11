<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Models\MetaInsightSnapshot;

class MetaInsightSnapshotFactory extends Factory
{
    protected $model = MetaInsightSnapshot::class;

    public function definition(): array
    {
        return [
            'ad_account_id'   => 'act_' . $this->faker->numberBetween(100, 999),
            'campaign_id'     => 'c' . $this->faker->numberBetween(1, 99),
            'campaign_name'   => $this->faker->words(2, true),
            'date'            => $this->faker->dateTimeBetween('-30 days')->format('Y-m-d'),
            'breakdown_type'  => 'none',
            'breakdown_value' => '',
            'impressions'     => $this->faker->numberBetween(100, 5000),
            'reach'           => $this->faker->numberBetween(50, 4000),
            'spend'           => $this->faker->randomFloat(2, 5, 500),
            'clicks'          => $this->faker->numberBetween(0, 300),
            'link_clicks'     => $this->faker->numberBetween(0, 200),
            'leads'           => $this->faker->numberBetween(0, 10),
        ];
    }

    public function gender(string $value): static
    {
        return $this->state(fn (): array => ['breakdown_type' => 'gender', 'breakdown_value' => $value]);
    }
}
