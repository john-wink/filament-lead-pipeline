<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Models\LeadReportSchedule;

class LeadReportScheduleFactory extends Factory
{
    protected $model = LeadReportSchedule::class;

    public function definition(): array
    {
        return [
            'report_uuid' => LeadReport::factory(),
            'frequency'   => 'weekly',
            'weekday'     => 1,
            'send_time'   => '08:00',
            'recipients'  => [$this->faker->safeEmail()],
            'attach_pdf'  => true,
            'is_active'   => true,
        ];
    }
}
