<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\DTOs;

use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use Spatie\LaravelData\Data;

class FieldPresetData extends Data
{
    public function __construct(
        public string $name,
        public string $key,
        public LeadFieldTypeEnum $type,
        public bool $is_required = false,
        public bool $is_system = false,
        public bool $show_in_card = false,
        public bool $show_in_funnel = true,
        public ?array $options = null,
    ) {}
}
