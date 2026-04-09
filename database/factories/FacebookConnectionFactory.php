<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

class FacebookConnectionFactory extends Factory
{
    protected $model = FacebookConnection::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'facebook_user_id'   => $this->faker->numerify('fb-####'),
            'facebook_user_name' => $this->faker->name(),
            'access_token'       => $this->faker->sha256(),
            'token_expires_at'   => now()->addDays(60),
            'scopes'             => ['pages_manage_ads', 'leads_retrieval', 'pages_show_list'],
            'status'             => 'connected',
        ];
    }

    public function expired(): static
    {
        return $this->state(['status' => 'expired', 'token_expires_at' => now()->subDay()]);
    }
}
