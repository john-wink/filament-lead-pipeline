<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum LeadSourceStatusEnum: string implements HasColor, HasIcon, HasLabel
{
    case Draft  = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Error  = 'error';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft  => __('lead-pipeline::lead-pipeline.source_status.draft'),
            self::Active => __('lead-pipeline::lead-pipeline.source_status.active'),
            self::Paused => __('lead-pipeline::lead-pipeline.source_status.paused'),
            self::Error  => __('lead-pipeline::lead-pipeline.source_status.error'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft  => 'gray',
            self::Active => 'success',
            self::Paused => 'warning',
            self::Error  => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft  => 'heroicon-o-pencil',
            self::Active => 'heroicon-o-check-circle',
            self::Paused => 'heroicon-o-pause-circle',
            self::Error  => 'heroicon-o-exclamation-triangle',
        };
    }
}
