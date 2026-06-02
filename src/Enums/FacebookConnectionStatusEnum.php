<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum FacebookConnectionStatusEnum: string implements HasColor, HasIcon, HasLabel
{
    case Connected   = 'connected';
    case NeedsReauth = 'needs_reauth';
    case Disabled    = 'disabled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Connected   => __('lead-pipeline::lead-pipeline.facebook.status.connected'),
            self::NeedsReauth => __('lead-pipeline::lead-pipeline.facebook.status.needs_reauth'),
            self::Disabled    => __('lead-pipeline::lead-pipeline.facebook.status.disabled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Connected   => 'success',
            self::NeedsReauth => 'danger',
            self::Disabled    => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Connected   => 'heroicon-o-check-circle',
            self::NeedsReauth => 'heroicon-o-exclamation-triangle',
            self::Disabled    => 'heroicon-o-no-symbol',
        };
    }
}
