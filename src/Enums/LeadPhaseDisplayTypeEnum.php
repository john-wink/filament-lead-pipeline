<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasLabel;

enum LeadPhaseDisplayTypeEnum: string implements HasLabel
{
    case Kanban = 'kanban';
    case List   = 'list';

    public function getLabel(): string
    {
        return match ($this) {
            self::Kanban => 'Kanban',
            self::List   => __('lead-pipeline::lead-pipeline.phase_display_type.list'),
        };
    }
}
