<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Contracts;

use JohnWink\FilamentLeadPipeline\DTOs\ReportBrandingData;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;

interface ResolvesReportBranding
{
    public function resolve(LeadReport $report): ReportBrandingData;
}
