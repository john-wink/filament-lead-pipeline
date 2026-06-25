<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LeadPhaseTypeEnum: string implements HasColor, HasLabel
{
    case Open         = 'open';
    case InProgress   = 'in_progress';
    case Won          = 'won';
    case Lost         = 'lost';
    case Disqualified = 'disqualified';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open         => __('lead-pipeline::lead-pipeline.phase_type.open'),
            self::InProgress   => __('lead-pipeline::lead-pipeline.phase_type.in_progress'),
            self::Won          => __('lead-pipeline::lead-pipeline.phase_type.won'),
            self::Lost         => __('lead-pipeline::lead-pipeline.phase_type.lost'),
            self::Disqualified => __('lead-pipeline::lead-pipeline.phase_type.disqualified'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open         => 'gray',
            self::InProgress   => 'info',
            self::Won          => 'success',
            self::Lost         => 'danger',
            self::Disqualified => 'warning',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Won, self::Lost, self::Disqualified]);
    }

    public function defaultDisplayType(): LeadPhaseDisplayTypeEnum
    {
        return match ($this) {
            self::Won, self::Lost, self::Disqualified => LeadPhaseDisplayTypeEnum::List,
            default                                   => LeadPhaseDisplayTypeEnum::Kanban,
        };
    }
}
