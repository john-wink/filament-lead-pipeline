<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\DTOs;

use Spatie\LaravelData\Data;

class SourcePresetData extends Data
{
    public function __construct(
        public string $name,
        public string $driver,
        public array $config = [],
    ) {}
}
