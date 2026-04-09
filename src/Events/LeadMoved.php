<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

class LeadMoved
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Lead $lead,
        public LeadPhase $fromPhase,
        public LeadPhase $toPhase,
    ) {}
}
