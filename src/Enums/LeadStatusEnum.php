<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum LeadStatusEnum: string implements HasColor, HasIcon, HasLabel
{
    case Active    = 'active';
    case Won       = 'won';
    case Lost      = 'lost';
    case Converted = 'converted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active    => __('lead-pipeline::lead-pipeline.status.active'),
            self::Won       => __('lead-pipeline::lead-pipeline.status.won'),
            self::Lost      => __('lead-pipeline::lead-pipeline.status.lost'),
            self::Converted => __('lead-pipeline::lead-pipeline.status.converted'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active    => 'primary',
            self::Won       => 'success',
            self::Lost      => 'danger',
            self::Converted => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Active    => 'heroicon-o-arrow-path',
            self::Won       => 'heroicon-o-check-circle',
            self::Lost      => 'heroicon-o-x-circle',
            self::Converted => 'heroicon-o-arrow-right-circle',
        };
    }
}
