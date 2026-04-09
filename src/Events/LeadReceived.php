<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

class LeadReceived
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public LeadSource $source,
        public array $payload,
    ) {}
}
