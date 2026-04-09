<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;

class LeadStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Lead $lead,
        public LeadStatusEnum $oldStatus,
        public LeadStatusEnum $newStatus,
    ) {}
}
