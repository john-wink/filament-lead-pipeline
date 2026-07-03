<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasLabel;

enum ImmoScoutEnvironmentEnum: string implements HasLabel
{
    case Production = 'production';
    case Sandbox    = 'sandbox';

    public function getLabel(): string
    {
        return match ($this) {
            self::Production => __('lead-pipeline::lead-pipeline.immoscout.environment_production'),
            self::Sandbox    => __('lead-pipeline::lead-pipeline.immoscout.environment_sandbox'),
        };
    }

    public function baseUrl(): string
    {
        return match ($this) {
            self::Production => 'https://rest.immobilienscout24.de/restapi',
            self::Sandbox    => 'https://rest.sandbox-immobilienscout24.de/restapi',
        };
    }
}
