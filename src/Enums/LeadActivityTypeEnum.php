<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum LeadActivityTypeEnum: string implements HasColor, HasIcon, HasLabel
{
    case Created     = 'created';
    case Moved       = 'moved';
    case Updated     = 'updated';
    case Converted   = 'converted';
    case Note        = 'note';
    case Call        = 'call';
    case Email       = 'email';
    case Assignment  = 'assignment';
    case FollowUp    = 'follow_up';
    case Transferred = 'transferred';

    public function getLabel(): string
    {
        return match ($this) {
            self::Created     => __('lead-pipeline::lead-pipeline.activity.created'),
            self::Moved       => __('lead-pipeline::lead-pipeline.activity.moved'),
            self::Updated     => __('lead-pipeline::lead-pipeline.activity.updated'),
            self::Converted   => __('lead-pipeline::lead-pipeline.activity.converted'),
            self::Note        => __('lead-pipeline::lead-pipeline.activity.note'),
            self::Call        => __('lead-pipeline::lead-pipeline.activity.call'),
            self::Email       => __('lead-pipeline::lead-pipeline.activity.email'),
            self::Assignment  => __('lead-pipeline::lead-pipeline.activity.assignment'),
            self::FollowUp    => __('lead-pipeline::lead-pipeline.activity.follow_up'),
            self::Transferred => __('lead-pipeline::lead-pipeline.activity.transferred'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Created     => 'success',
            self::Moved       => 'info',
            self::Updated     => 'primary',
            self::Converted   => 'warning',
            self::Note        => 'gray',
            self::Call        => 'success',
            self::Email       => 'info',
            self::Assignment  => 'primary',
            self::FollowUp    => 'warning',
            self::Transferred => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Created     => 'heroicon-o-plus-circle',
            self::Moved       => 'heroicon-o-arrows-right-left',
            self::Updated     => 'heroicon-o-pencil',
            self::Converted   => 'heroicon-o-arrow-right-circle',
            self::Note        => 'heroicon-o-document-text',
            self::Call        => 'heroicon-o-phone',
            self::Email       => 'heroicon-o-envelope',
            self::Assignment  => 'heroicon-o-user-plus',
            self::FollowUp    => 'heroicon-o-bell-alert',
            self::Transferred => 'heroicon-o-arrow-right-circle',
        };
    }
}
