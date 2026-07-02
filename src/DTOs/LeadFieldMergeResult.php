<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\DTOs;

final readonly class LeadFieldMergeResult
{
    public function __construct(
        public int $moved,
        public int $deduplicated,
        public int $conflicts,
        public int $sourcesUpdated = 0,
    ) {}
}
