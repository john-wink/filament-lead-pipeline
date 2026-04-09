<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Contracts;

use Illuminate\Database\Eloquent\Model;
use JohnWink\FilamentLeadPipeline\Models\Lead;

interface LeadConverter
{
    public function getDisplayName(): string;

    public function getTargetModelClass(): string;

    /** @return array<\Filament\Forms\Components\Component> */
    public function getConversionFormSchema(Lead $lead): array;

    public function convert(Lead $lead, array $additionalData = []): Model;

    /** @return array<string> */
    public function validate(Lead $lead): array;
}
