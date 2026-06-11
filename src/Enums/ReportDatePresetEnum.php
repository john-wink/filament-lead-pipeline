<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

enum ReportDatePresetEnum: string
{
    case Today      = 'today';
    case Last7Days  = 'last7days';
    case Last30Days = 'last30days';
    case Last90Days = 'last90days';
    case ThisMonth  = 'this_month';
    case LastMonth  = 'last_month';
    case AllTime    = 'all_time';
    case Custom     = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Today      => __('lead-pipeline::reports.presets.today'),
            self::Last7Days  => __('lead-pipeline::reports.presets.last7days'),
            self::Last30Days => __('lead-pipeline::reports.presets.last30days'),
            self::Last90Days => __('lead-pipeline::reports.presets.last90days'),
            self::ThisMonth  => __('lead-pipeline::reports.presets.this_month'),
            self::LastMonth  => __('lead-pipeline::reports.presets.last_month'),
            self::AllTime    => __('lead-pipeline::reports.presets.all_time'),
            self::Custom     => __('lead-pipeline::reports.presets.custom'),
        };
    }
}
