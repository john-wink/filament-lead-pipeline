<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Traits;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadConversion;

trait ConvertsLeads
{
    public function leadConversion(): MorphOne
    {
        return $this->morphOne(LeadConversion::class, 'convertible');
    }

    public function wasConvertedFromLead(): bool
    {
        return $this->leadConversion()->exists();
    }

    public function getSourceLead(): ?Lead
    {
        return $this->leadConversion?->lead;
    }
}
