<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Exceptions;

use RuntimeException;

class ReportPdfNotConfiguredException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Kein ReportPdfRenderer konfiguriert. Binde JohnWink\FilamentLeadPipeline\Contracts\ReportPdfRenderer an eine Implementierung (config: lead-pipeline.reports.pdf_renderer).');
    }
}
