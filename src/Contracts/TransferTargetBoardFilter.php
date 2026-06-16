<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface TransferTargetBoardFilter
{
    /**
     * Schränkt die Liste auswählbarer Ziel-Boards projektspezifisch ein.
     */
    public function apply(Builder $query, ?Model $tenant): Builder;
}
