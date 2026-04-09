<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;

class FacebookPageFactory extends Factory
{
    protected $model = FacebookPage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'page_id'                => $this->faker->numerify('page-####'),
            'page_name'              => $this->faker->company(),
            'page_access_token'      => $this->faker->sha256(),
            'is_webhooks_subscribed' => false,
        ];
    }

    public function subscribed(): static
    {
        return $this->state(['is_webhooks_subscribed' => true]);
    }
}
