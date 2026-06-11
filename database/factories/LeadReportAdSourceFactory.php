<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Models\LeadReportAdSource;

class LeadReportAdSourceFactory extends Factory
{
    protected $model = LeadReportAdSource::class;

    public function definition(): array
    {
        return [
            'report_uuid'              => LeadReport::factory(),
            'facebook_connection_uuid' => FacebookConnection::factory(),
            'ad_account_id'            => 'act_' . $this->faker->numberBetween(100, 999),
            'campaign_ids'             => null,
            'sync_status'              => 'pending',
        ];
    }
}
