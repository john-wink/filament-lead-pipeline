<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JohnWink\FilamentLeadPipeline\Concerns\HasConfigurablePrimaryKey;
use JohnWink\FilamentLeadPipeline\Database\Factories\LeadReportScheduleFactory;

class LeadReportSchedule extends Model
{
    use HasConfigurablePrimaryKey;
    use HasFactory;

    protected $table = 'lead_report_schedules';

    protected $guarded = [];

    public function report(): BelongsTo
    {
        return $this->belongsTo(LeadReport::class, 'report_uuid');
    }

    public function computeNextRunAt(?CarbonInterface $from = null): CarbonImmutable
    {
        $from            = CarbonImmutable::make($from ?? now());
        [$hour, $minute] = array_map(intval(...), explode(':', mb_substr((string) $this->send_time, 0, 5)));

        if ('weekly' === $this->frequency) {
            $candidate = $from->startOfDay()->setTime($hour, $minute);

            while ((int) $candidate->dayOfWeekIso !== (int) $this->weekday || $candidate->lte($from)) {
                $candidate = $candidate->addDay()->setTime($hour, $minute);
            }

            return $candidate;
        }

        $candidate = $from->startOfMonth()->setDay(min((int) $this->day_of_month, 28))->setTime($hour, $minute);

        return $candidate->gt($from) ? $candidate : $candidate->addMonthNoOverflow();
    }

    protected static function newFactory(): LeadReportScheduleFactory
    {
        return LeadReportScheduleFactory::new();
    }

    protected function casts(): array
    {
        return [
            'recipients'   => 'array',
            'attach_pdf'   => 'boolean',
            'is_active'    => 'boolean',
            'last_sent_at' => 'datetime',
            'next_run_at'  => 'datetime',
        ];
    }
}
