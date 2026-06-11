<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use JohnWink\FilamentLeadPipeline\Contracts\ReportPdfRenderer;
use JohnWink\FilamentLeadPipeline\Exceptions\ReportPdfNotConfiguredException;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Support\ReportDateRange;

class NullReportPdfRenderer implements ReportPdfRenderer
{
    public function render(LeadReport $report, ReportDateRange $range): string
    {
        throw ReportPdfNotConfiguredException::make();
    }
}
