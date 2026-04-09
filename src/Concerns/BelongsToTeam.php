<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTeam
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(
            config('lead-pipeline.tenancy.model'),
            config('lead-pipeline.tenancy.foreign_key'),
        );
    }

    public function scopeForTeam(Builder $query, mixed $teamId): Builder
    {
        if ( ! config('lead-pipeline.tenancy.enabled')) {
            return $query;
        }

        return $query->where(config('lead-pipeline.tenancy.foreign_key'), $teamId);
    }
}
