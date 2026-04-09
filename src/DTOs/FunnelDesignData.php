<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\DTOs;

use Spatie\LaravelData\Data;

class FunnelDesignData extends Data
{
    public function __construct(
        public string $background_color = '#FFFFFF',
        public string $primary_color = '#3B82F6',
        public string $text_color = '#1F2937',
        public string $font_family = 'Inter, sans-serif',
        public string $border_radius = '8px',
        public string $max_width = '640px',
        public string $logo_position = 'center',
        public bool $show_progress_bar = true,
        public bool $show_step_numbers = true,
        public ?string $background_image = null,
        public ?string $logo_url = null,
        public ?string $favicon_url = null,
        public ?string $custom_css = null,
        public ?string $success_heading = null,
        public ?string $success_text = null,
        public ?string $success_redirect_url = null,
        public ?string $success_calendar_embed = null,
    ) {}
}
