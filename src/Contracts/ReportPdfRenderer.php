<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Contracts;

use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Support\ReportDateRange;

interface ReportPdfRenderer
{
    /** @return string Binärer PDF-Inhalt */
    public function render(LeadReport $report, ReportDateRange $range): string;
}
