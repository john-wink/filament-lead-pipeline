<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;

trait HasLeadBoards
{
    public function leadBoards(): HasMany
    {
        return $this->hasMany(
            LeadBoard::class,
            config('lead-pipeline.tenancy.foreign_key', 'team_uuid'),
            $this->getKeyName(),
        );
    }

    public function leadReports(): HasMany
    {
        return $this->hasMany(
            LeadReport::class,
            config('lead-pipeline.tenancy.foreign_key', 'team_uuid'),
            $this->getKeyName(),
        );
    }
}
