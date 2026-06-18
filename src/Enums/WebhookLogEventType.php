<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WebhookLogEventType: string implements HasColor, HasLabel
{
    case Incoming     = 'incoming';
    case Registration = 'registration';
    case Verify       = 'verify';
    case StatusCheck  = 'status_check';

    public function getLabel(): string
    {
        return match ($this) {
            self::Incoming     => __('lead-pipeline::lead-pipeline.webhook_log.type.incoming'),
            self::Registration => __('lead-pipeline::lead-pipeline.webhook_log.type.registration'),
            self::Verify       => __('lead-pipeline::lead-pipeline.webhook_log.type.verify'),
            self::StatusCheck  => __('lead-pipeline::lead-pipeline.webhook_log.type.status_check'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Incoming     => 'info',
            self::Registration => 'primary',
            self::Verify       => 'gray',
            self::StatusCheck  => 'gray',
        };
    }
}
