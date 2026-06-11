<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\DTOs;

use Spatie\LaravelData\Data;

class ReportBrandingData extends Data
{
    public function __construct(
        public ?string $logoUrl,
        public ?string $coLogoUrl,
        public string $accentColor,
        public ?string $claimHtml,
        public ?string $footerText,
        public ?string $contact = null,
        public ?string $imprintUrl = null,
    ) {}
}
