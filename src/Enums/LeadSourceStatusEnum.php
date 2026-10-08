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
    case Ended  = 'ended';

    /** @return list<self> */
    public static function activeGroup(): array
    {
        return [self::Active, self::Draft];
    }

    /** @return list<self> */
    public static function inactiveGroup(): array
    {
        return [self::Paused, self::Error, self::Ended];
    }

    public function isInActiveGroup(): bool
    {
        return in_array($this, self::activeGroup(), true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft  => __('lead-pipeline::lead-pipeline.source_status.draft'),
            self::Active => __('lead-pipeline::lead-pipeline.source_status.active'),
            self::Paused => __('lead-pipeline::lead-pipeline.source_status.paused'),
            self::Error  => __('lead-pipeline::lead-pipeline.source_status.error'),
            self::Ended  => __('lead-pipeline::lead-pipeline.source_status.ended'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft  => 'gray',
            self::Active => 'success',
            self::Paused => 'warning',
            self::Error  => 'danger',
            self::Ended  => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft  => 'heroicon-o-pencil',
            self::Active => 'heroicon-o-check-circle',
            self::Paused => 'heroicon-o-pause-circle',
            self::Error  => 'heroicon-o-exclamation-triangle',
            self::Ended  => 'heroicon-o-stop-circle',
        };
    }
}
