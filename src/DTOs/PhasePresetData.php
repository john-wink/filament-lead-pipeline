<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\DTOs;

use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use Spatie\LaravelData\Data;

class PhasePresetData extends Data
{
    public function __construct(
        public string $name,
        public LeadPhaseTypeEnum $type,
        public LeadPhaseDisplayTypeEnum $display_type = LeadPhaseDisplayTypeEnum::Kanban,
        public string $color = '#6B7280',
        public int $sort = 0,
        public bool $auto_convert = false,
        public ?string $conversion_target = null,
    ) {}
}
