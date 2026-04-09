<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\DTOs;

use Spatie\LaravelData\Data;

class WebhookPayloadData extends Data
{
    public function __construct(
        public string $driver,
        public string $source_id,
        /** @var array<string, mixed> */
        public array $raw_payload,
        /** @var array<string, mixed> */
        public array $mapped_fields = [],
        public ?string $signature = null,
    ) {}
}
