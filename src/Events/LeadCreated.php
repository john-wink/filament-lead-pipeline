<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Enums\LeadOriginEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;

class LeadCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Lead $lead,
        public readonly LeadOriginEnum $origin = LeadOriginEnum::Realtime,
    ) {}
}
