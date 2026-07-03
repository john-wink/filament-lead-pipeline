<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum LeadSourceTypeEnum: string implements HasColor, HasIcon, HasLabel
{
    case Zapier           = 'zapier';
    case Meta             = 'meta';
    case Api              = 'api';
    case Funnel           = 'funnel';
    case Manual           = 'manual';
    case InternalTransfer = 'internal_transfer';
    case ImmoScout24      = 'immoscout24';

    public function getLabel(): string
    {
        return match ($this) {
            self::Zapier           => 'Zapier',
            self::Meta             => 'Facebook / Meta',
            self::Api              => 'API',
            self::Funnel           => 'Funnel',
            self::Manual           => __('lead-pipeline::lead-pipeline.source_type.manual'),
            self::InternalTransfer => __('lead-pipeline::lead-pipeline.source_type.internal_transfer'),
            self::ImmoScout24      => 'ImmoScout24',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Zapier           => 'warning',
            self::Meta             => 'info',
            self::Api              => 'gray',
            self::Funnel           => 'success',
            self::Manual           => 'primary',
            self::InternalTransfer => 'info',
            self::ImmoScout24      => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Zapier           => 'heroicon-o-bolt',
            self::Meta             => 'heroicon-o-share',
            self::Api              => 'heroicon-o-code-bracket',
            self::Funnel           => 'heroicon-o-funnel',
            self::Manual           => 'heroicon-o-pencil-square',
            self::InternalTransfer => 'heroicon-o-arrow-right-circle',
            self::ImmoScout24      => 'heroicon-o-home-modern',
        };
    }

    public function getDriverClass(): ?string
    {
        $drivers = config('lead-pipeline.drivers', []);

        return $drivers[$this->value] ?? null;
    }
}
