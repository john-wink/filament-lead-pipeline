<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum LeadSourceTypeEnum: string implements HasColor, HasIcon, HasLabel
{
    case Zapier = 'zapier';
    case Meta   = 'meta';
    case Api    = 'api';
    case Funnel = 'funnel';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Zapier => 'Zapier',
            self::Meta   => 'Facebook / Meta',
            self::Api    => 'API',
            self::Funnel => 'Funnel',
            self::Manual => __('lead-pipeline::lead-pipeline.source_type.manual'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Zapier => 'warning',
            self::Meta   => 'info',
            self::Api    => 'gray',
            self::Funnel => 'success',
            self::Manual => 'primary',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Zapier => 'heroicon-o-bolt',
            self::Meta   => 'heroicon-o-share',
            self::Api    => 'heroicon-o-code-bracket',
            self::Funnel => 'heroicon-o-funnel',
            self::Manual => 'heroicon-o-pencil-square',
        };
    }

    public function getDriverClass(): ?string
    {
        $drivers = config('lead-pipeline.drivers', []);

        return $drivers[$this->value] ?? null;
    }
}
