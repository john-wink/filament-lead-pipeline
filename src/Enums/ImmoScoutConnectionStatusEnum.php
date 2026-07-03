<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ImmoScoutConnectionStatusEnum: string implements HasColor, HasLabel
{
    case Connected = 'connected';
    case Error     = 'error';
    case Disabled  = 'disabled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Connected => __('lead-pipeline::lead-pipeline.immoscout.status_connected'),
            self::Error     => __('lead-pipeline::lead-pipeline.immoscout.status_error'),
            self::Disabled  => __('lead-pipeline::lead-pipeline.immoscout.status_disabled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Connected => 'success',
            self::Error     => 'danger',
            self::Disabled  => 'gray',
        };
    }
}
