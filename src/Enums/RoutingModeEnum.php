<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasLabel;

enum RoutingModeEnum: string implements HasLabel
{
    case Manual = 'manual';
    case Fixed  = 'fixed';
    case Open   = 'open';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => __('lead-pipeline::lead-pipeline.routing_mode.manual'),
            self::Fixed  => __('lead-pipeline::lead-pipeline.routing_mode.fixed'),
            self::Open   => __('lead-pipeline::lead-pipeline.routing_mode.open'),
        };
    }

    public function isAutoRouting(): bool
    {
        return self::Fixed === $this;
    }
}
