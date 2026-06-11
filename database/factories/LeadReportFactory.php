<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;

class LeadReportFactory extends Factory
{
    protected $model = LeadReport::class;

    public function definition(): array
    {
        return [
            'name'                => $this->faker->words(3, true),
            'is_active'           => true,
            'date_preset_default' => 'last30days',
            'date_locked'         => false,
        ];
    }
}
